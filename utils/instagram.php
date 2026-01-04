<?php

/**
 * Récupère le Facebook System User Access Token depuis les variables d'environnement
 * 
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return string Token d'accès système Facebook ou chaîne vide si manquant
 */
function getAccessToken($spxApiResponse)
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
 * ÉTAPE 1 : Appel à /me/accounts pour obtenir les pages Facebook
 * 
 * @param string $systemAccessToken Token d'accès système Facebook
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return array|null Tableau contenant 'pageId' et 'pageAccessToken' de la première page trouvée, ou null en cas d'erreur
 */
function getAccessPageToken($systemAccessToken, $spxApiResponse)
{
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

/**
 * Récupère l'ID du compte Instagram Business lié à la page Facebook
 * ÉTAPE 2 : Appel à /{pageId}?fields=instagram_business_account
 * 
 * @param string $pageAccessToken Token d'accès de la page Facebook
 * @param string $pageId ID de la page Facebook
 * @param array $spxApiResponse Tableau de réponse de l'API Simplixity
 * @return string|null ID du compte Instagram Business, ou null en cas d'erreur
 */
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