<?php

define('CONSTANTE', 'ValorConstante');

function testFunction($param)
{
    if($param === 'test') 
    {
        echo "param fasfasd";
    }

    global $otraVar;
    $varNoUsada = 23;
    $otraVar = "fasfsafas";

    $fecha = explode(":", "2023:04:14");

    $nuevoValor = $param;

    for ($i = 0; $i < 10; $i++) {
        echo $i;
    }

    return $nuevoValor;
}

testFunction("test");
echo "<br>Blablabadasla afssad bla1";
