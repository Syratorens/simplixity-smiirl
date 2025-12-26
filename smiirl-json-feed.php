<?php
/**
 * Smiirl JSON Feed
 * Point d'entrée pour le flux JSON Smiirl
 * Renvoie toujours un JSON au format: {"number": 1200}
 */

// Charger les fonctions
require_once __DIR__ . '/functions.php';

// Initialiser l'environnement
loadEnv();

// Configuration des headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Récupérer le service demandé
$service = $_GET['service'] ?? $_ENV['SERVICE'] ?? 'instagram';

// Récupérer et afficher les données
$result = getData($service);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
