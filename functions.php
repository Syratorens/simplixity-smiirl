<?php
/**
 * API Simple pour récupérer des statistiques depuis différents services
 * Bibliothèque de fonctions - Ne pas appeler directement
 */


/**
 * Récupère le nombre d'abonnés Instagram
 * API non-officielle Instagram
 */
function getInstagramFollowersApiV1($spxApiResponse) {
    
    $username = $_ENV['INSTAGRAM_USERNAME'] ?? '';
    
    if (empty($username)) {
        $spxApiResponse['error'] = 'Nom d\'utilisateur Instagram manquant';
        return $spxApiResponse;
    }
    
    // Vérifier le cache
    $cacheLifetime = (int)($_ENV['CACHE_LIFETIME'] ?? 120);
    $cachedData = getCache($spxApiResponse['service'], $cacheLifetime);
    if ($cachedData !== null) {
        return $cachedData;
    }

    $url = "https://www.instagram.com/api/v1/users/web_profile_info/?username=" . urlencode($username);
    $spxApiResponse['url'] = $url;
    
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
            $spxApiResponse['number'] = (int)$json['data']['user']['edge_followed_by']['count'];
            // Vous pouvez ajouter d'autres valeurs ici
            // $spxApiResponse['following'] = (int)$json['data']['user']['edge_follow']['count'];
            // $spxApiResponse['posts'] = (int)$json['data']['user']['edge_owner_to_timeline_media']['count'];
            
            $spxApiResponse = array_merge($spxApiResponse, formatApiResponse($httpCode));
            
            // Sauvegarder dans le cache (sans l'info de cache)
            setCache($spxApiResponse['service'], $spxApiResponse);
            
            return $spxApiResponse;
        }
    }
    
    // Formater l'erreur avec la fonction dédiée
    $spxApiResponse = array_merge(
        $spxApiResponse,
        formatApiResponse(
            $httpCode,
            'Impossible de récupérer le nombre d\'abonnés. Instagram a peut-être modifié sa structure.',
            'Utilisez l\'API officielle Instagram Graph API avec un access token'
        )
    );
    
    // Sauvegarder l'erreur dans le cache pour éviter de spammer Instagram
    setCache($spxApiResponse['service'], $spxApiResponse);
    
    return $spxApiResponse;
}

/**
 * Récupère le nombre d'abonnés Instagram via l'API officielle Graph API v24
 * Nécessite un Facebook System User Access Token
 * Utilise 3 requêtes chaînées pour obtenir le followers_count
 */
function getInstagramFollowers($spxApiResponse)
{
    $accessToken = getAccessTokenFromEnv($spxApiResponse);
    if (empty($accessToken)) {
        return $spxApiResponse;
    }

    // ÉTAPE 0 : On récupère le cache et si valide, on retourne directement l'api Reponse mis en cache
    $cacheLifetime = (int) ($_ENV['CACHE_LIFETIME'] ?? 120);
    $cachedData = getCache($spxApiResponse['service'], $cacheLifetime);
    if ($cachedData !== null) {
        return $cachedData;
    }

    // Si pas de cache, ou invalide, on continue
    // ÉTAPE 1 : Récupérer le page_access_token (celui de la première page Facebook liée au compte : valide 2H)
    $pageTokenData = getAccessPageToken($accessToken, $spxApiResponse);
    if(!isset($pageTokenData['pageAccessToken']) || !isset($pageTokenData['pageId'])) {
        return $spxApiResponse; // Erreur déjà formatée dans la fonction
    }

    // ÉTAPE 2 : Récupérer l'ID du compte Instagram Business  (Valide tout le temps) =====
    $pageAccessToken = $pageTokenData['pageAccessToken'];
    $pageId = $pageTokenData['pageId'];
    $instagramBusinessAccountId = getInstagramBusinessAccountId($pageAccessToken, $pageId, $spxApiResponse);
    if (empty($instagramBusinessAccountId)) {
        return $spxApiResponse; // Erreur déjà formatée dans la fonction
    }

    // ÉTAPE 3 : Récupérer le nombre d'abonnés =====
    return getInstagramFollowersCount($pageAccessToken, $instagramBusinessAccountId, $spxApiResponse);

}

/**
 * Récupère les données selon le service demandé
 */
function getData($service) {
    $spxApiResponse = [];
    $spxApiResponse["service"] = $service;
    $spxApiResponse["number"] = 0;

    
    switch (strtolower($service)) {
        case 'instagram-v1':
            return getInstagramFollowersApiV1($spxApiResponse);
        case 'instagram':
            require_once __DIR__ . '/utils/instagram.php';      
            return getInstagramFollowers($spxApiResponse);
        
        // Vous pouvez ajouter d'autres services ici
        // case 'twitter':
        //     return getTwitterFollowers();
        // case 'youtube':
        //     return getYoutubeSubscribers();
        
        default:
            $spxApiResponse['response']['error'] = 'Service non supporté';
            return $spxApiResponse;
    }
}
