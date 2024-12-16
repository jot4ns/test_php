
<?php
/**
 * Clase que se ocupa de la facturación de empresas de grupo 1, 1L y 3.
 * 
 * @see http://192.168.99.23/mantisbt/view.php?id=2745
 */
class Facturador {
    // Conexión a la base de datos de Sigge
    public $conn_pg;
    // Nombre corto de empresa que factura
    private $nombre_corto_empresa;
    // Periodo de facturación
    private $periodo;
    // Objeto cuentas contables
    private $cuentas_contables;
    // Pais
    private $pais;
    // Hay o no texto debug
    // Documentos tributarios específicos a facturar
    private $documento_tributario_id_cli;
    // ID de empresa emisora
    private $empresa_emisora_id;
    // Objeto de envío a ERP de la empresa.
    private $obj_conexion_erp;
    // Arreglo de errores
    public $arr_errores_facturacion;
    /**
     * El constructor de la clase
     *
     * @param resource $conn_pg    El link a la base de datos
     * @param string $nombre_corto_empresa    Nombre corto de la empresa que factura
     * @param string $periodo    Periodo de facturación
     * @param array $cuentas_contables    Array de cuentas contables
     * @param string $pais    El país al que refiere Sigge
     * @param array $documento_tributario_id_cli    Array de documento_tributario_id que se facturarán
     * @return void
     */
    public function __construct($conn_pg, $nombre_corto_empresa, $periodo, $cuentas_contables, $pais, $debug, $documento_tributario_id_cli)
    {
        $this->conn_pg = $conn_pg;
        $this->nombre_corto_empresa = $nombre_corto_empresa;
        $this->periodo = $periodo;
        $this->cuentas_contables = $cuentas_contables;
        $this->pais = $pais;
        $this->debug = $debug;
        $this->documento_tributario_id_cli = $documento_tributario_id_cli;
    }
    /**
     * Factura los ingresos
     *
     * @return void
     */
    public function facturar() {
        $obj_config = Config::getInstance();
        $ahora = time();
        $empresa_emisora = null;
        $this->empresa_emisora_id = null;
        $this->arr_errores_facturacion = array();
        try {
            //obtengo todas las empresas con sus grupos
            $empresa_obj = new Empresa($this->conn_pg);
            $empresa_emisora = $empresa_obj->get_empresa_por_nombre($this->nombre_corto_empresa);
            $this->empresa_emisora_id = $empresa_emisora['empresa_id'];
            if(!in_array($empresa_emisora['grupo_facturacion'], GRUPOS_PERMITIDO_FACTURACION)) {
                printf("Error: Empresa del grupo %s, no permitido facturar\n", $empresa_emisora['grupo_facturacion']);
                exit(1);
            }
        }
        catch (Exception $exc) {
            Util::debug(str_replace("Error: Error:", "Error:", "Error: ".$exc->getMessage())."\n", $this->debug);
            exit(1);
        }
        // Si la empresa emisora tiene una clase que implementa la integración
        // con su ERP, instanciarla.
        if($empresa_emisora['clase_integracion_erp'] != null)
        {
            $clase_integracion_erp = $empresa_emisora['clase_integracion_erp'];
            $this->obj_conexion_erp = new $clase_integracion_erp($this->conn_pg, $empresa_emisora['nombre_corto_cdec']);
            $this->obj_conexion_erp->inicializar();
        }
        // empresas que utilizan algun ERP
        switch ($empresa_emisora['nombre_corto_cdec'])
        {
            case 'HIDROPALOMA':
            case 'PARRONAL':
            case 'VILLA_PRAT_ENERGY':
            case 'ALTO_SOLAR':
            case 'HIDROCONFIANZA':
                $this->obj_conexion_erp = new ERPScotta($this->conn_pg, $this->nombre_corto_empresa, $usar_moneda_adicional = false);
                $this->obj_conexion_erp ->inicializar();
                break;
            default:
                // acá no hacer nada ya que, este switch es en particular para las
                // empresas que tienen adicionalmente a la facturacion una accion
                // posterior hacia algún sistema ERP o similar
                break;
        }

        // documentos a facturar según empresa y período
        $arr_documentos = DocumentoTributario::listar_documentos($this->conn_pg, $this->empresa_emisora_id, $this->periodo, null);

        //ordeno de mayor a menor los documentos por monto_total
        usort($arr_documentos, function($a, $b){
            if($a['monto_total'] == $b['monto_total']){
                return 0;
            }

            return $a['monto_total'] < $b['monto_total']?1:-1;
        });

        $proveedor_facturacion = Empresa::proveedor_facturacion($this->conn_pg, $this->empresa_emisora_id);
        // objeto para facturar con el facturador electrónico que corresponde a
        // esta empresa.
        if ($proveedor_facturacion != null) {
            $obj_facturador = new $proveedor_facturacion($this->conn_pg);
        }

        // Verificar si es que la empresa usa generador de folios (neozet
        // administra los folios).
        $tiene_generador_folios = false;
        if (in_array('generador_folios', $empresa_emisora['modulos']))
        {
            $obj_generador_folios = new GeneradorFolios($this->conn_pg, $this->empresa_emisora_id);
            $tiene_generador_folios = true;
        }

        // ciclo por todos los documentos tributarios a enviar
        foreach ($arr_documentos as $documento)
        {
            if (!empty($this->documento_tributario_id_cli) and !in_array($documento['documento_tributario_id'], $this->documento_tributario_id_cli)) {
                continue;
            }
            
            // si el documento incluye un concepto (CC) que no es facturable masivo,
            // no lo enviamos a facturacion.cl
            foreach ($documento['detalle'] as $detalle) {
                $cuenta_contable = $this->cuentas_contables[$detalle['cuenta_contable_id']];
                if ($cuenta_contable['facturable_masivo'] == 'n') {
                    continue 2;
                }
            }

            // Si esta marcado para no facturar, se omite, mantis 1212
            if ($documento['facturar'] != 's') {
                continue;
            }

            // Si ya se envió exitosamente el documento (ya tiene folio), no
            // enviar nuevamente. 
            if ($documento['folio_dte'] != '') {
                continue;
            }
            // comprobamos que este documento no haya sido enviado previamente.
            if (isset($this->obj_conexion_erp)) {
                if ($documento['envio_erp_id'] != NULL) {
                    continue;
                }
            }

            Util::debug("* empresa: {$this->nombre_corto_empresa} - id: {$documento['documento_tributario_id']}\n", $this->debug);

            // ahora crear el documento XML
            try
            {
                // XXX: El folio siempre debe ser 0 para facturacion.cl, a menos
                // que se estén realizando pruebas!
                $documento['folio_dte'] = 0;
                $documento['fecha_hora_envio'] = date("Y-m-d H:i:s", $ahora);
                if ($documento['fecha_vencimiento'] == null) {
                    if ($this->pais == 'chile') {
                        $dias_habiles = 7;
                    }
                    elseif ($this->pais == 'peru') {
                        $dias_habiles = 3;
                    }

                    $documento['fecha_vencimiento'] = date("Y-m-d", Util::sumar_dias_habiles($ahora, $dias_habiles));
                }
                else {
                    $documento['fecha_vencimiento'] = date("Y-m-d", strtotime($documento['fecha_vencimiento']));
                }

                // cambiar fecha de vencimiento para contratos de GR_POWER y otras
                // empresas.
                if (in_array($empresa_emisora['nombre_corto_cdec'], ['GR_POWER', 'CHUNGUNGO', 'SANTIAGO SOLAR', 'CABO LEONES I', 'CGE_C', 'CABO_LEONES_II', 'CABO LEONES III', 'GPG_SOLAR','SAFIRA_ENERGIA']))
                {
                    $prefactura = ProcesoPrefacturacion::recuperar_contrato_de_factura($this->conn_pg, $documento['documento_tributario_id']);
                    // Código para GR_POWER. Altera la fecha de vencimiento para todos
                    // los documentos que provienen de un contrato, cualquier contrato.
                    if (($documento['tipo_carga'] == TipoCargaDT::CONTRATOS) and $prefactura != null and is_numeric($prefactura['contrato_id']))
                    {
                        $obj_contrato = new Contrato($this->conn_pg);
                        $datos_contrato = $obj_contrato->get_contrato($prefactura['contrato_id']);
                        $clase_contrato = $datos_contrato['clase'];
                        require_once("/var/www/sigge/biblio/contratos/$clase_contrato.php");
                        // El método siempre existe porque está definido en
                        // ContratoBase. Pero sólo lo usamos si retorna distinto
                        // de null.
                        if ($clase_contrato::fecha_vencimiento_emision() != null) {
                            $documento['fecha_vencimiento'] = $clase_contrato::fecha_vencimiento_emision();
                        }
                    }
                }

                if ($tiene_generador_folios == true) {
                    $documento['folio_dte'] = $obj_generador_folios->siguiente_folio($documento['tipo_dte']);
                }

                // Si el coordinado utiliza otras fechas, obtenerlas.
                if (isset($this->obj_conexion_erp) and method_exists($this->obj_conexion_erp, 'get_fecha_hora_envio')) {
                    $documento['fecha_hora_envio'] = $this->obj_conexion_erp->get_fecha_hora_envio();
                }
                // XXX: Si empresa emisora utiliza Nubox, debemos generar archivos
                // CSV en lugar de XML.
                if ($proveedor_facturacion == 'Nubox')
                {
                    $documento['folio_dte'] = $documento['documento_tributario_id'];
                    $contenido_csv = DocumentoTributario::generar_csv($this->conn_pg, $documento);
                    $contenido_csv_referencias = DocumentoTributario::generar_csv_referencias($documento);
                }
                else
                {
                    // Si el coordinado utiliza otras fechas, obtenerlas.
                    if (isset($this->obj_conexion_erp) and method_exists($this->obj_conexion_erp, 'get_fecha_vencimiento')) {
                        $documento['fecha_vencimiento'] = $this->obj_conexion_erp->get_fecha_vencimiento();
                    }
                    $contenido_xml = DocumentoTributario::generar_xml($this->conn_pg, $documento, $empresa_emisora);
                }
            }
            // Si falla creación de un documento, ignorarlo y continuar.
            catch (Exception $exc) {
                print $exc->getMessage()."\n";
                continue;
            }
            // Llamada a facturadores
            if ($proveedor_facturacion == 'Nubox')
            {
                try
                {
                    $identificador_nubox = null;
                    list($folio, $identificador_nubox) = $obj_facturador->enviar_documento($empresa_emisora['empresa_id'], $contenido_csv, $contenido_csv_referencias, $documento['documento_tributario_id']);
                    $contenido = $obj_facturador->obtener_pdf($empresa_emisora['empresa_id'], $identificador_nubox);
                    $url_pdf = $obj_config->get_parametro('url_base').'modulo/ingresos/descargar_emitido.php?factura_id='.$documento['documento_tributario_id'].'&folio_dte='.$folio.'&h='.Permiso::hash_documento($documento['documento_tributario_id'], $folio);
                    $ret = @file_put_contents(CorreoDTE::PATH_EMITIDOS.'/'.$documento['documento_tributario_id'].'_'.$folio.'.pdf', $contenido);
                    if ($ret === false) {
                        throw new Exception("Error: No se pudo escribir en ".CorreoDTE::PATH_EMITIDOS);
                    }
                    DocumentoTributario::persistir_url_pdf($this->conn_pg, $documento['documento_tributario_id'], $url_pdf);
                    Util::debug("* folio: {$folio}\n", $this->debug);
                }
                // Si falló la emisión/envío de un documento a facturacion.cl, ignoramos
                // ese documento.
                catch (Exception $exc) {
                    $this->error_facturacion($documento['documento_tributario_id'], $exc->getMessage(), $identificador_nubox);
                    continue;
                }
                // actualizar folio, fecha de emisión y fecha de vencimiento en la
                // base de datos
                $documento['folio_dte'] = $folio;
                DocumentoTributario::marcar_envio($this->conn_pg, $documento);
            }
            // Sólo si facturamos con facturacion.cl los llamamos.
            if ($proveedor_facturacion == 'FacturacionCL')
            {
                try {
                    $folio = $obj_facturador->enviar_documento($empresa_emisora['empresa_id'], base64_encode($contenido_xml));
                }
                // Si falló la emisión/envío de un documento a facturacion.cl, ignoramos
                // ese documento.
                catch (Exception $exc) {
                    $this->error_facturacion($documento['documento_tributario_id'], $exc->getMessage());
                    continue;
                }
                // actualizar folio, fecha de emisión y fecha de vencimiento en la
                // base de datos
                $documento['folio_dte'] = $folio;
                DocumentoTributario::marcar_envio($this->conn_pg, $documento);
                // hacemos una pausa de 1 segundo entre llamados
                sleep(1);
                Util::debug("* folio: {$folio}\n", $this->debug);
            }
            // Sólo si facturamos con webfactura los llamamos.
            if ($proveedor_facturacion == 'WebFactura')
            {
                try {
                    $data = $obj_facturador->enviar_documento($empresa_emisora['empresa_id'], $contenido_xml, $documento['tipo_dte']);
                    $folio = $data['folio']; 
                    $url_pdf = $data['url_pdf'];

                    DocumentoTributario::persistir_url_pdf($this->conn_pg, $documento['documento_tributario_id'], $url_pdf);
                    Util::debug("* folio: {$folio}\n", $this->debug);
                }
                catch (Exception $exc) {
                    $this->error_facturacion($documento['documento_tributario_id'], $exc->getMessage());
                    continue;
                }
                // actualizar folio, fecha de emisión y fecha de vencimiento en la
                // base de datos
                $documento['folio_dte'] = $folio;

                DocumentoTributario::marcar_envio($this->conn_pg, $documento);
                // hacemos una pausa de 1 segundo entre llamados
                sleep(1);
            }
            // Sólo si facturamos con Acepta los llamamos.
            if ($proveedor_facturacion == 'Acepta')
            {
                try {
                    $data = $obj_facturador->enviar_documento($contenido_xml, $documento['documento_tributario_id']);
                    sleep(1);
                    $documento['folio_dte'] = $data['folio'];
                    $url_pdf = $data['url_pdf'];
                    // TODO: persistir_url_pdf ?
                    // actualizar folio, fecha de emisión y fecha de vencimiento en la
                    // base de datos
                    DocumentoTributario::marcar_envio($this->conn_pg, $documento);
                    DocumentoTributario::persistir_url_pdf($this->conn_pg, $documento['documento_tributario_id'], $url_pdf);
                    Util::debug("* folio: {$data['folio']}\n", $this->debug);
                }
                catch (Exception $exc) {
                    $this->error_facturacion($documento['documento_tributario_id'], $exc->getMessage());
                    continue;
                }
            }
            // Sólo si facturamos con FullDTE los llamamos.
            if ($proveedor_facturacion == 'FullDTE' or $proveedor_facturacion == 'Ingefactura')
            {
                try {
                    $documento['forma_pago'] = 2;
                    $contenido_xml = DocumentoTributario::generar_xml($this->conn_pg, $documento, $empresa_emisora);
                    $data = $obj_facturador->enviar_documento($empresa_emisora['empresa_id'], $contenido_xml);
                    $documento['folio_dte'] = $data['folio'];
                    $url_pdf = $data['url_pdf'];

                    DocumentoTributario::marcar_envio($this->conn_pg, $documento);
                    DocumentoTributario::persistir_url_pdf($this->conn_pg, $documento['documento_tributario_id'], $url_pdf);
                    Util::debug("* folio: {$documento['folio_dte']}\n", $this->debug);
                }
                catch (Exception $exc) {
                    $this->error_facturacion($documento['documento_tributario_id'], $exc->getMessage());
                    continue;
                }
            }
            // Sólo si facturamos con FullDTE los llamamos.
            if ($proveedor_facturacion == 'GDExpress')
            {
                try {
                    $contenido_xml = DocumentoTributario::generar_xml($this->conn_pg, $documento, $empresa_emisora, $proveedor_facturacion);
                    $folio = $obj_facturador->enviar_documento($empresa_emisora['empresa_id'], $contenido_xml);
                    $documento['folio_dte'] = $folio;
                    DocumentoTributario::marcar_envio($this->conn_pg, $documento);
                    $contenido = $obj_facturador->obtener_pdf($empresa_emisora['empresa_id'], $folio, $documento['tipo_dte'], $obj_config);
                    $url_pdf = $obj_config->get_parametro('url_base').'modulo/ingresos/descargar_emitido.php?factura_id='.$documento['documento_tributario_id'].'&folio_dte='.$folio.'&h='.Permiso::hash_documento($documento['documento_tributario_id'], $folio);
                    $ret = @file_put_contents(CorreoDTE::PATH_EMITIDOS.'/'.$documento['documento_tributario_id'].'_'.$folio.'.pdf', $contenido);
                    if ($ret === false) {
                        throw new Exception("Error: No se pudo escribir en ".CorreoDTE::PATH_EMITIDOS);
                    }
                    DocumentoTributario::persistir_url_pdf($this->conn_pg, $documento['documento_tributario_id'], $url_pdf);
                    Util::debug("* folio: {$folio}\n", $this->debug);
                }
                catch (Exception $exc) {
                    $this->error_facturacion($documento['documento_tributario_id'], $exc->getMessage());
                    continue;
                }
            }
            if (isset($this->obj_conexion_erp)) {
                $this->obj_conexion_erp->agregar_documento($documento);
            }
        }
    }

    /**
     * Genera archivos según indique la clase ERP de la empresa. Requiere facturar antes.
     *
     * @return void
     */
    public function generar_archivo() {
        // generar los archivos solo si hay por lo menos 1 documento
        if (isset($this->obj_conexion_erp) and $this->obj_conexion_erp->total_documentos() > 0)
        {
            $this->obj_conexion_erp->generar_archivo($this->periodo);
        }
    }

    /**
     * Envía archivos según indique la clase ERP de la empresa y registra los envíos. Requiere facturar antes.
     *
     * @return void
     */
    public function enviar_archivo() {
        // enviar los archivos solo si hay por lo menos 1 documento
        if (isset($this->obj_conexion_erp) and $this->obj_conexion_erp->total_documentos() > 0)
        {
            $this->obj_conexion_erp->enviar_ingresos($this->periodo);
            $this->obj_conexion_erp->registrar_envios();
        }
    }

    /**
     * Imprime un error de facturación y lo guarda en el arreglo de errores.
     *
     * @param int $documento_tributario_id ID del ingreso.
     * @return void
     */
    private function error_facturacion($documento_tributario_id, $excepcion, $identificador = null) {
        if (is_null($identificador)) {
            print "Error para documento id $documento_tributario_id: $excepcion\n";
            $identificador = "";
        }
        else {
            print "Error para documento id $documento_tributario_id, $identificador: $excepcion\n";
        }
        $this->arr_errores_facturacion[] = "$documento_tributario_id,$identificador,".str_replace("\n", "", $excepcion);
    }
    /**
     * Envía los errores de facturación por correo a Operaciones.
     *
     * @return void
     */
    public function enviar_errores_facturacion() {
        // Si no hay errores, no se envía nada
        if (count($this->arr_errores_facturacion) == 0) {
            exit(0);
        }
        // Se genera archivo XLS
        $archivo_temp_csv = '/tmp/sigge'.sprintf("%07d", rand());
        $fh = fopen($archivo_temp_csv, "w");
        fwrite($fh, "documento_tributario_id,identificador,error\n");
        foreach ($this->arr_errores_facturacion as $fila) {
            fwrite($fh, "$fila\n");
        }
        fclose($fh);
        $path_xls = '/tmp';
        $ahora = time();
        $nombre = "facturacion_".str_replace(' ','_',$this->nombre_corto_empresa)."_".date('YmdHis', $ahora).".xls";
        Util::exportar_a_excel($archivo_temp_csv, $nombre, $path_xls);
        // Se envía correo
        $destinatarios_to = [Config::CORREO_OPERACIONES];
        $destinatarios_cc = [];
        $obj_config = Config::getInstance();
        if ($obj_config->get_parametro('ambiente') != 'produccion') {
            $destinatarios_to = [Config::CORREO_PRUEBAS_RECP];
        }
        $obj_mail = new MailAviso("Aviso de errores facturador para empresa {$this->nombre_corto_empresa} ".date('Y-m-d H:i', $ahora), Config::CORREO_SIGGE);
        $texto_aviso = "Estimado equipo de Operaciones,<br/><br/>";
        $texto_aviso .= "Se adjunta archivo con errores del proceso de facturación de la empresa {$this->nombre_corto_empresa}.";
        $texto_aviso .= "<br/>";
        $texto_aviso .= " Agradecemos tomar nota de este aviso.<br/> Saluda atentamente<br/><br/><br/>";
        $texto_aviso .= "PS: Se han omitido los acentos de este correo. Este aviso fue generado de manera automatica por el sistema <a href='http://www.sigge.cl/'>SIGGE</a>, favor no responder.<br/>";
        $texto_aviso = Util::quitar_acentos($texto_aviso);
        $obj_mail->agregar_adjunto("$path_xls/$nombre", 'application/vnd.ms-excel');
        $obj_mail->enviar($destinatarios_to, $texto_aviso, $destinatarios_cc);
    }
}
?>
