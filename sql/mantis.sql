INSERT INTO contrato (contrato_id, empresa_vendedora_id, empresa_compradora_id, nombre, clase, licitacion_id, contrato_codigo_compradora, fecha_inicio, fecha_fin, hes_en_detalle, requiere_oc_inicial, posible_nota_credito, flujo_aprobacion, fecha_firma_contrato, reporte_ignorar_facturables, reporte_ignorar_no_facturables, enviar_avisos_flujo, activo)
VALUES ((SELECT MAX(contrato_id) FROM contrato)+1, 
(SELECT empresa_id FROM empresa_receptora WHERE nombre_corto_cdec = 'LIPIGAS'),
(SELECT empresa_id FROM empresa_receptora WHERE nombre_corto_cdec = 'TONELERIA NACIONAL'), 
'Contrato entre LIPIGAS y TONELERIA NACIONAL', 'LipigasCMG',NULL,543808,'2024-04-01', '2028-03-31','n','n','n','nv', NULL, 'n', 's', 's', 's');

INSERT INTO adfasdasdas VALUES((SELECT MAX(contrato_id) FROM contrato), (SELECT barra_id FROM barra WHERE nombre = 'CURACAVI' AND voltaje = 12));

INSERT INTO servicio_contrato (contrato_id, codigo, punto_suministro_barra_id, descripcion, numero_cliente, periodo_desde, periodo_hasta, direccion, valor_inicial_cpi) 
VALUES ((SELECT MAX(contrato_id) FROM contrato),'LIP051', 
(SELECT barra_id FROM barra WHERE nombre = 'CURACAVI' AND voltaje = 12),'', NULL,
'2024-04-01', '2028-03-31', NULL,);
