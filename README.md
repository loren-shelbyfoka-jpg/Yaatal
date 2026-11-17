# YAATAL — prototype PHP

## Démarrer en local

Depuis le dossier du projet, lancez le serveur PHP intégré :

```powershell
php -S localhost:8000
```

Le terminal affiche l’adresse du serveur, par exemple :

```text
PHP 8.5.0 Development Server (http://localhost:8000) started
```

Cliquez sur <http://localhost:8000> dans le terminal ou copiez cette adresse dans votre navigateur.

Le projet utilise PHP 8.1+, PDO SQLite et une base créée automatiquement. En local, les données sont enregistrées dans `.yaatal-storage` à côté du projet. `YAATAL_DATA_DIR` permet de choisir un autre emplacement.

## Mettre en ligne avec un hébergeur PHP

Vercel ne fournit pas de runtime PHP officiel. Pour garder cette application PHP et sa base SQLite persistante, choisissez un hébergeur qui prend en charge PHP 8.1 ou supérieur, l'extension PDO SQLite et le stockage persistant (par exemple un hébergement PHP/cPanel).

1. Créez un dépôt GitHub et envoyez-y les fichiers du projet. Le fichier `.gitignore` exclut les bases locales et les fichiers d'environnement.
2. Publiez les fichiers dans le dossier web de votre hébergement, ou connectez le dépôt GitHub si l'hébergeur le permet.
3. Configurez ces variables d'environnement dans le panneau de l'hébergeur **avant la première visite** :

   | Variable | Valeur |
   | --- | --- |
   | `APP_ENV` | `production` |
   | `YAATAL_DATA_DIR` | Chemin absolu vers un dossier persistant situé hors du dossier web |
   | `YAATAL_ADMIN_EMAIL` | Adresse e-mail de l'administrateur YAATAL |
   | `YAATAL_ADMIN_PASSWORD` | Mot de passe unique d'au moins 12 caractères |

4. Activez HTTPS et vérifiez que le dossier de données est accessible en écriture par PHP, mais pas publiquement accessible sur le Web.
5. Ouvrez le site. Au premier démarrage, l'application crée le schéma SQLite et le compte administrateur avec les identifiants configurés. En mode `production`, elle ne crée pas les comptes, producteurs, produits ou ravitaillements de démonstration ; ajoutez les données réelles depuis l'espace technique.
6. Sauvegardez régulièrement le dossier de données, qui contient `yaatal.sqlite`.

Si l'application a déjà été ouverte avec une base contenant les comptes de démonstration, ne réutilisez pas cette base en production : configurez un dossier de données vide. Les variables de déploiement ne doivent jamais être ajoutées au dépôt GitHub.

## Comptes de démonstration locaux

En local, les comptes de démonstration utilisent le mot de passe `Yaatal2026!` :

- Client : `client@yaatal.local`
- Producteur : `producteur@yaatal.local`
- Équipe technique : `admin@yaatal.local`

Ces comptes et mots de passe sont réservés au développement local. `APP_ENV=production` désactive leur création.

## Modèle de fonctionnement

- Le catalogue client ne montre que les produits actifs validés par YAATAL. Le prix unitaire additionne la part producteur, la commission YAATAL et le forfait transport YAATAL configurés par l’équipe.
- Les clients choisissent une date de ravitaillement et précommandent avant sa clôture. L’espace technique distingue ces commandes des bons d’achat de YAATAL auprès des producteurs.
- Un bon d’achat fournisseur doit passer par un rendez-vous de contrôle qualité avant de pouvoir être marqué comme validé.
- Les producteurs voient le modèle général construit à partir d’un jeu de données explicitement fictif. Leur profil et leurs rendements passés sont facultatifs ; les projections personnelles restent indicatives.
- Les photos sont chargées depuis Unsplash et nécessitent une connexion Internet.

Le catalogue, les producteurs, prix, dates et quotas initiaux sont des données de démonstration à remplacer par les informations réelles de YAATAL.
