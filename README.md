# API Simple pour Smiirl

Application PHP simple qui récupère des statistiques depuis différents services et renvoie un flux JSON au format `{"number": 1200}`.

## 🚀 Installation

1. Cloner ou télécharger ce projet
2. Copier `.env.example` vers `.env`
3. Configurer vos identifiants dans le fichier `.env`
4. Construire et démarrer avec Docker : `make create`

## 📝 Configuration

### Instagram

Pour utiliser l'API Instagram, vous devez obtenir un access token :

#### Option 1 : Instagram Basic Display API (Recommandé)

1. Aller sur [Facebook Developers](https://developers.facebook.com/)
2. Créer une application
3. Ajouter le produit "Instagram Basic Display"
4. Configurer les URLs de redirection
5. Générer un Access Token
6. Copier le token dans le fichier `.env`

#### Option 2 : Instagram Graph API (Pour les comptes professionnels)

1. Avoir un compte Instagram professionnel/créateur lié à une page Facebook
2. Créer une app Facebook
3. Obtenir un access token avec les permissions nécessaires
4. Utiliser l'API Graph pour récupérer les données

### Fichier .env

```env
INSTAGRAM_USERNAME=wennwood
INSTAGRAM_ACCESS_TOKEN=votre_token_ici
SERVICE=instagram
PORT=8080
```

**Note**: Le port par défaut est 8080. Vous pouvez le modifier dans le fichier `.env` selon vos besoins (par exemple si le port est déjà utilisé sur votre NAS).

## 🔧 Utilisation

### Avec Docker (Recommandé)

```bash
# Construire et démarrer l'application
make create

# Autres commandes disponibles
make start   # Démarrer les conteneurs
make stop    # Arrêter les conteneurs
make restart # Redémarrer les conteneurs
make clean   # Supprimer les conteneurs
make logs    # Voir les logs
make shell   # Accéder au shell du conteneur
make help    # Afficher l'aide
```

### Sans Docker

```bash
php -S localhost:8000
```

### Accéder à l'API

- Par défaut (service défini dans .env) : `http://localhost:8000/`
- Spécifier un service : `http://localhost:8000/?service=instagram`

### Réponse

```json
{
  "number": 1200
}
```

En cas d'erreur :

```json
{
  "error": "Message d'erreur"
}
```

## 🔌 Ajouter d'autres services

Pour ajouter un nouveau service (Twitter, YouTube, etc.), ajoutez simplement une nouvelle fonction dans `index.php` :

```php
function getTwitterFollowers() {
    // Votre code ici
    return ['number' => $count];
}
```

Puis ajoutez le case dans le switch de la fonction `getData()`.

## 📦 Déploiement

L'application peut être déployée sur n'importe quel hébergement PHP (version 7.0+).

## ⚠️ Notes importantes

- Assurez-vous que cURL est activé sur votre serveur PHP
- Gardez votre `.env` confidentiel (ne le commitez jamais sur Git)
- Les tokens d'accès Instagram expirent, pensez à les renouveler régulièrement
- Pour une utilisation en production, ajoutez une mise en cache des résultats
