<?php

define('constante', 'ValorConstante');

function testFunction($param)
{
    if ($param== 'test'){
echo "param test";
}
    global $otraVar;
    $varNoUsada = 23;
    $otraVar = "eSAFASrwerrrr";
    $fecha = split(":", "2023:04:14");
    $nuevoValor = & $param;
    for ($i = 0; $i<10;$i++){
        echo $i;
    }
    return $nuevoValor;
}
testFunction("test");

echo "<br>Blafdqwsabla test2";
