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
    $cacheLifetime = (int)($_ENV['CACHE_LIFETIME'] ?? 120);
    $cachedData = getCache($result['service'], $cacheLifetime);
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

function getAccessToken($spxApiResponse) {
    $accessToken = $_ENV['FACEBOOK_SYSTEM_USER_ACCESS_TOKEN'] ?? '';

    if (empty($accessToken)) {
        $spxApiResponse = array_merge(
            $spxApiResponse,
            formatApiResponse(
                0,
                'Facebook System User Access Token manquant',
                'Configurez FACEBOOK_SYSTEM_USER_ACCESS_TOKEN dans le fichier .env'
            )
        );
    }

    return $accessToken;
}

function getAccessPageToken($systemAccessToken, $spxApiResponse) {
    $accountsUrl = "https://graph.facebook.com/v24.0/me/accounts";
    $facebookApiResponse = [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $accountsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $systemAccessToken
    ]);

    $accountsResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($accountsResponse)) {
        $errorData = json_decode($accountsResponse, true);
        $errorMessage = 'Étape 1 - Impossible de récupérer les pages Facebook';
        $suggestion = 'Vérifiez que votre access token a la permission pages_show_list';

        if (isset($errorData['error']['message'])) {
            $errorMessage .= ' - ' . $errorData['error']['message'];
        }

        $spxApiResponse = array_merge(
            $spxApiResponse,
            formatApiResponse($httpCode, $errorMessage, $suggestion)
        );

        $spxApiResponse['response']['debug_response'] = $errorData ?? [];

        // Sauvegarder l'erreur dans le cache pour éviter de spammer l'API
        setCache($spxApiResponse['service'], $spxApiResponse);

        return;
    }

    $accountsData = json_decode($accountsResponse, true);

    // Récupérer la première page et son access token
    if (!isset($accountsData['data'][0]['id']) || !isset($accountsData['data'][0]['access_token'])) {
        $spxApiResponse = array_merge(
            $spxApiResponse,
            formatApiResponse(
                $httpCode,
                'Étape 1 - Aucune page Facebook trouvée',
                'Assurez-vous d\'avoir une page Facebook connectée à votre compte'
            )
        );

        $spxApiResponse['response']['debug_accounts'] = $accountsData;

        // Sauvegarder l'erreur dans le cache pour éviter de spammer l'API
        setCache($spxApiResponse['service'], $spxApiResponse);

        return;
    }

    $facebookApiResponse["pageId"] = $accountsData['data'][0]['id'];
    $facebookApiResponse["pageAccessToken"] = $accountsData['data'][0]['access_token'];
    return $facebookApiResponse;

}

function getInstagramBusinessAccountId($pageAccessToken, $pageId, $spxApiResponse)
{
    $pageUrl = "https://graph.facebook.com/v24.0/{$pageId}?fields=instagram_business_account";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $pageAccessToken
    ]);

    $pageResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($pageResponse)) {
        $errorData = json_decode($pageResponse, true);
        $errorMessage = 'Étape 2 - Impossible de récupérer le compte Instagram Business';
        $suggestion = 'Vérifiez que votre page Facebook est bien connectée à un compte Instagram Business et que le Page Access Token est valide';

        if (isset($errorData['error']['message'])) {
            $errorMessage .= ' - ' . $errorData['error']['message'];
        }

        $$spxApiResponse = array_merge(
            $spxApiResponse,
            formatApiResponse($httpCode, $errorMessage, $suggestion)
        );

        $spxApiResponse['response']['debug_response'] = $errorData ?? [];

        // Sauvegarder l'erreur dans le cache pour éviter de spammer l'API
        setCache($spxApiResponse['service'], $spxApiResponse);

        return;
    }

    $pageData = json_decode($pageResponse, true);

    if (!isset($pageData['instagram_business_account']['id'])) {
        $$spxApiResponse = array_merge(
            $spxApiResponse,
            formatApiResponse(
                $httpCode,
                'Étape 2 - Aucun compte Instagram Business trouvé',
                'Assurez-vous que votre page Facebook est connectée à un compte Instagram Business ou Creator'
            )
        );

        $$spxApiResponse['response']['debug_page'] = $pageData;

        // Sauvegarder l'erreur dans le cache pour éviter de spammer l'API
        setCache($spxApiResponse['service'], $spxApiResponse);

        return;
    }

    return $pageData['instagram_business_account']['id'];
}

function getInstagramFollowersCount($pageAccessToken, $instagramBusinessAccountId, $spxApiResponse) {
    $followersUrl = "https://graph.facebook.com/v24.0/{$instagramBusinessAccountId}?fields=followers_count,username";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $followersUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $pageAccessToken
    ]);

    $followersResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($followersResponse)) {
        $data = json_decode($followersResponse, true);

        if (isset($data['followers_count'])) {
            $spxApiResponse['number'] = (int) $data['followers_count'];
            if (isset($data['username'])) {
                $spxApiResponse['username'] = $data['username'];
            }

            $spxApiResponse = array_merge($spxApiResponse, formatApiResponse($httpCode));

            // Sauvegarder dans le cache (sans l'info de cache)
            setCache($spxApiResponse['service'], $spxApiResponse);

            return $spxApiResponse;
        }
    }

    // Erreur lors de la récupération des données
    $errorData = json_decode($followersResponse, true);
    $errorMessage = 'Étape 3 - Impossible de récupérer le nombre d\'abonnés';

    if (isset($errorData['error']['message'])) {
        $errorMessage .= ' - ' . $errorData['error']['message'];
    }

    $spxApiResponse = array_merge(
        $spxApiResponse,
        formatApiResponse(
            $httpCode,
            $errorMessage,
            'Vérifiez que votre Page Access Token a les permissions instagram_basic et instagram_manage_insights'
        )
    );

    $spxApiResponse['response']['debug_response'] = $errorData ?? [];

    // Sauvegarder l'erreur dans le cache pour éviter de spammer l'API
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
    $accessToken = getAccessToken($spxApiResponse);
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
    // ÉTAPE 1 : Récupérer le page_access_token (celui de la première page Facebook liée au compte)
    $pageTokenData = getAccessPageToken($accessToken, $spxApiResponse);
    if(!isset($pageTokenData['pageAccessToken']) || !isset($pageTokenData['pageId'])) {
        return $spxApiResponse; // Erreur déjà formatée dans la fonction
    }

    // ÉTAPE 2 : Récupérer l'ID du compte Instagram Business =====
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
