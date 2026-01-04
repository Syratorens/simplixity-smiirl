<?php

// Charger les variables d'environnement
function loadEnv($path = '.env')
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}

/**
 * Récupère les données depuis le cache
 * @param string $cacheKey Clé du cache (nom du service)
 * @param int $lifetime Durée de vie du cache en secondes
 * @return array|null Données en cache ou null si invalide/inexistant
 */
function getCache($cacheKey, $lifetime = 120)
{
    $cacheDir = __DIR__ . '/../cache';
    $cacheFile = $cacheDir . '/' . $cacheKey . '-data.json';

    if (!file_exists($cacheFile)) {
        return null;
    }

    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge >= $lifetime) {
        return null;
    }

    $cachedData = json_decode(file_get_contents($cacheFile), true);
    if ($cachedData) {
        $cachedData['cache'] = [
            'age' => $cacheAge . 's',
            'created_at' => date('Y-m-d H:i:s', filemtime($cacheFile)),
            'lifetime' => $lifetime . 's'
        ];
        return $cachedData;
    }

    return null;
}

/**
 * Sauvegarde les données dans le cache
 * @param string $cacheKey Clé du cache (nom du service)
 * @param array $data Données à mettre en cache
 * @return bool Succès de l'opération
 */
function setCache($cacheKey, $data)
{
    $cacheDir = __DIR__ . '/../cache';

    // Créer le dossier cache s'il n'existe pas
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/' . $cacheKey . '-data.json';
    return file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * Formate une réponse API avec des informations de débogage
 * @param int $httpCode Code HTTP de la réponse
 * @param string $error Message d'erreur (optionnel)
 * @param string $suggestion Suggestion pour résoudre le problème (optionnel)
 * @return array Tableau formaté avec la réponse
 */
function formatApiResponse($httpCode, $error = null, $suggestion = null)
{
    $response = ['api_http_code' => $httpCode];

    if ($error !== null) {
        $response['error'] = $error;
    }

    if ($suggestion !== null) {
        $response['suggestion'] = $suggestion;
    }

    return array("response" => $response);
}