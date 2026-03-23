<?php
// Mocking data logic for now
$notas = '{"Q1":"A", "Total": "85.5", "Percentual de Acertos": "80%"}';
$decoded = json_decode($notas, true);
print_r($decoded);
