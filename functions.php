<?php
/**
 * API Simple pour récupérer des statistiques depuis différents services
 * Bibliothèque de fonctions - Ne pas appeler directement
 */

// Charger les variables d'environnement
function loadEnv($path = '.env') {
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
function getCache($cacheKey, $lifetime = 120) {
    $cacheDir = __DIR__ . '/cache';
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
function setCache($cacheKey, $data) {
    $cacheDir = __DIR__ . '/cache';
    
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
function formatApiResponse($httpCode, $error = null, $suggestion = null) {
    $response = ['api_http_code' => $httpCode];
    
    if ($error !== null) {
        $response['error'] = $error;
    }
    
    if ($suggestion !== null) {
        $response['suggestion'] = $suggestion;
    }
    
    return array("response" => $response);
}

/**
 * Récupère le nombre d'abonnés Instagram
 * API non-officielle Instagram
 */
function getInstagramFollowersApiV1($result) {
    
    $username = $_ENV['INSTAGRAM_USERNAME'] ?? '';
    
    if (empty($username)) {
        $result['error'] = 'Nom d\'utilisateur Instagram manquant';
        return $result;
    }
    
    // Vérifier le cache
    $cachedData = getCache($result['service'], 120);
    if ($cachedData !== null) {
        return $cachedData;
    }

    $url = "https://www.instagram.com/api/v1/users/web_profile_info/?username=" . urlencode($username);
    $result['url'] = $url;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'X-IG-App-ID: 936619743392459',
        'X-Requested-With: XMLHttpRequest'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($response)) {
        $json = json_decode($response, true);
        
        if (isset($json['data']['user']['edge_followed_by']['count'])) {
            $result['number'] = (int)$json['data']['user']['edge_followed_by']['count'];
            // Vous pouvez ajouter d'autres valeurs ici
            // $result['following'] = (int)$json['data']['user']['edge_follow']['count'];
            // $result['posts'] = (int)$json['data']['user']['edge_owner_to_timeline_media']['count'];
            
            $result = array_merge($result, formatApiResponse($httpCode));
            
            // Sauvegarder dans le cache (sans l'info de cache)
            setCache($result['service'], $result);
            
            return $result;
        }
    }
    
    // Formater l'erreur avec la fonction dédiée
    $result = array_merge(
        $result,
        formatApiResponse(
            $httpCode,
            'Impossible de récupérer le nombre d\'abonnés. Instagram a peut-être modifié sa structure.',
            'Utilisez l\'API officielle Instagram Graph API avec un access token'
        )
    );
    
    // Sauvegarder l'erreur dans le cache pour éviter de spammer Instagram
    setCache($result['service'], $result);
    
    return $result;
}

/**
 * Récupère les données selon le service demandé
 */
function getData($service) {
    $result = [];
    $result["service"] = $service;
    $result["number"] = 0;

    
    switch (strtolower($service)) {
        case 'instagram-v1':
            return getInstagramFollowersApiV1($result);
        
        // Vous pouvez ajouter d'autres services ici
        // case 'twitter':
        //     return getTwitterFollowers();
        // case 'youtube':
        //     return getYoutubeSubscribers();
        
        default:
            $result['response']['error'] = 'Service non supporté';
            return $result;
    }
}
