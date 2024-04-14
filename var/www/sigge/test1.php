<?php

define('constante', 'ValorConstante');

function testFunction($param)
{
    if ($param== 'test'){
echo "param test";
}
    global $otraVar;
    $varNoUsada = 23;
