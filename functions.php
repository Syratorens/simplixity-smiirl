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
 * Récupère le nombre d'abonnés Instagram
 */
function getInstagramFollowers() {
    $result = [];
    
    $username = $_ENV['INSTAGRAM_USERNAME'] ?? '';
    
    if (empty($username)) {
        $result['error'] = 'Nom d\'utilisateur Instagram manquant';
        return $result;
    }
    
    // Méthode : API non-officielle Instagram
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

    $result['http_code'] = $httpCode;
    
    if ($httpCode === 200 && !empty($response)) {
        $json = json_decode($response, true);
        
        if (isset($json['data']['user']['edge_followed_by']['count'])) {
            $result['number'] = (int)$json['data']['user']['edge_followed_by']['count'];
            // Vous pouvez ajouter d'autres valeurs ici
            // $result['following'] = (int)$json['data']['user']['edge_follow']['count'];
            // $result['posts'] = (int)$json['data']['user']['edge_owner_to_timeline_media']['count'];
            return $result;
        }
    }
    
    $result['error'] = 'Impossible de récupérer le nombre d\'abonnés. Instagram a peut-être modifié sa structure.';
    $result['suggestion'] = 'Utilisez l\'API officielle Instagram Graph API avec un access token';
    return $result;
}

/**
 * Récupère les données selon le service demandé
 */
function getData($service) {
    switch (strtolower($service)) {
        case 'instagram':
            return getInstagramFollowers();
        
        // Vous pouvez ajouter d'autres services ici
        // case 'twitter':
        //     return getTwitterFollowers();
        // case 'youtube':
        //     return getYoutubeSubscribers();
        
        default:
            return ['error' => 'Service non supporté'];
    }
}
