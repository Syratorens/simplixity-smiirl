<?php
// =======================================================
// Fichier d'utils propres à l'intégration d'Instagram
// =======================================================

/**
 * Récupère le Page Access Token depuis le cache s'il est encore valide
 * 
 * @param int $lifetime Durée de vie du cache en secondes (défaut: 3600 = 1h)
 * @return array|null Tableau contenant 'pageId' et 'pageAccessToken', ou null si expiré/inexistant
 */
function getPageAccessTokenFromCache($lifetime = 3600)
{
    $cacheDir = __DIR__ . '/../cache';
    $cacheFile = $cacheDir . '/page_access_token.json';

    if (!file_exists($cacheFile)) {
        return null;
    }

    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge >= $lifetime) {
        return null;
    }

    $cachedData = json_decode(file_get_contents($cacheFile), true);
    if ($cachedData && isset($cachedData['pageId']) && isset($cachedData['pageAccessToken'])) {
        return $cachedData;
    }

    return null;
}

/**
 * Sauvegarde le Page Access Token dans le cache
 * 
 * @param string $pageId ID de la page Facebook
 * @param string $pageAccessToken Token d'accès de la page
 * @return bool Succès de l'opération
 */
function setPageAccessTokenToCache($pageId, $pageAccessToken)
{
    $cacheDir = __DIR__ . '/../cache';

    // Créer le dossier cache s'il n'existe pas
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/page_access_token.json';
    $data = [
        'pageId' => $pageId,
        'pageAccessToken' => $pageAccessToken,
        'cached_at' => date('Y-m-d H:i:s')
    ];

    return file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

/**
 * Récupère le Facebook System User Access Token depuis les variables d'environnement
 * 
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return string Token d'accès système Facebook ou chaîne vide si manquant
 */
function getAccessTokenFromEnv($spxApiResponse)
{
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

/**
 * Récupère le Page Access Token et l'ID de la page Facebook via l'API Graph
 * ÉTAPE 1 : Vérifie d'abord le cache (1h), sinon appel à /me/accounts
 * 
 * @param string $systemAccessToken Token d'accès système Facebook
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return array|null Tableau contenant 'pageId' et 'pageAccessToken' de la première page trouvée, ou null en cas d'erreur
 */
function getAccessPageToken($systemAccessToken, $spxApiResponse)
{
    // Étape 1 : Vérifier d'abord le cache (valide 1h)
    $cachedPageToken = getPageAccessTokenFromCache(3600);
    
    if ($cachedPageToken !== null) {
        return $cachedPageToken;
    }
    
    // Étape 2 : Si pas de cache valide, faire l'appel API
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
    
    // Étape 3 : Sauvegarder dans le cache pour 1h
    setPageAccessTokenToCache(
        $facebookApiResponse["pageId"],
        $facebookApiResponse["pageAccessToken"]
    );
    
    return $facebookApiResponse;

}

/**
 * Récupère l'ID du compte Instagram Business lié à la page Facebook
 * ÉTAPE 2 : Vérifie d'abord le .env, sinon appel à /{pageId}?fields=instagram_business_account
 * Si l'appel API réussit, sauvegarde l'ID dans le .env pour économiser les requêtes futures
 * 
 * @param string $pageAccessToken Token d'accès de la page Facebook
 * @param string $pageId ID de la page Facebook
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return string|null ID du compte Instagram Business, ou null en cas d'erreur
 */
function getInstagramBusinessAccountId($pageAccessToken, $pageId, $spxApiResponse)
{
    // Etape 1 : Vérifier d'abord si l'ID existe déjà dans le .env
    $cachedInstagramBusinessAccountId = $_ENV['INSTAGRAM_BUSINESS_ACCOUNT_ID'] ?? '';
    
    if (!empty($cachedInstagramBusinessAccountId)) {
        return $cachedInstagramBusinessAccountId;
    }
    

    // Etape 2 : Si non présent, faire l'appel API
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

    $instagramBusinessAccountId = $pageData['instagram_business_account']['id'];
    

    // Etape 3 : Sauvegarder l'ID dans le .env pour les prochaines requêtes
    $envPath = __DIR__ . '/../.env';
    $envContent = file_get_contents($envPath);
    
    // Vérifier si INSTAGRAM_BUSINESS_ACCOUNT_ID existe déjà
    if (strpos($envContent, 'INSTAGRAM_BUSINESS_ACCOUNT_ID=') !== false) {
        // Remplacer la valeur existante
        $envContent = preg_replace(
            '/INSTAGRAM_BUSINESS_ACCOUNT_ID=.*/m',
            'INSTAGRAM_BUSINESS_ACCOUNT_ID=' . $instagramBusinessAccountId,
            $envContent
        );
    } else {
        // Ajouter la nouvelle variable après FACEBOOK_SYSTEM_USER_ACCESS_TOKEN
        $envContent = preg_replace(
            '/(FACEBOOK_SYSTEM_USER_ACCESS_TOKEN=.*)/m',
            "$1\nINSTAGRAM_BUSINESS_ACCOUNT_ID=" . $instagramBusinessAccountId,
            $envContent
        );
    }
    
    file_put_contents($envPath, $envContent);
    $_ENV['INSTAGRAM_BUSINESS_ACCOUNT_ID'] = $instagramBusinessAccountId;

    return $instagramBusinessAccountId;
}

/**
 * Récupère le nombre d'abonnés du compte Instagram Business
 * ÉTAPE 3 : Appel à /{instagramBusinessAccountId}?fields=followers_count,username
 * 
 * @param string $pageAccessToken Token d'accès de la page Facebook
 * @param string $instagramBusinessAccountId ID du compte Instagram Business
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return array Réponse complète avec le nombre d'abonnés et les informations de cache
 */
function getInstagramFollowersCount($pageAccessToken, $instagramBusinessAccountId, $spxApiResponse)
{
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