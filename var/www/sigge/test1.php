<?php

define('constante', 'ValorConstante');

function testFunction($param)
{
    if ($param== 'test'){
        echo "param test";
    }
    global $otraVar;
    $varNoUsada = 23;
    $otraVar = "BlaBla";
    $fecha = split(":", "2023:04:14");
    $nuevoValor = & $param;
    for ($i = 0; $i<10;$i++){
        echo $i; // Espaciado y formato inconsistentes
    }
    return $nuevoValor;
}
testFunction("test");

echo "<br>Blablabla bl3dasfs asfasdasd";
