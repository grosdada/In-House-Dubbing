# Guide de démarrage — In-House-Dubbing

In-House-Dubbing sert à faire enregistrer des **voix maquettes** à distance, réplique par réplique, à partir d'un scénario. La personne qui fait les voix reçoit un lien, enregistre dans son navigateur, et vous récupérez aussitôt les prises, transformées si vous le voulez avec les voix d'ElevenLabs.

## 1. Installer (une seule fois, 10 minutes)

Il faut un hébergement web avec **PHP 8** et **HTTPS** (Hostinger, o2switch, OVH… dès 3 € par mois).

1. Téléchargez le dépôt (bouton **Code › Download ZIP** sur GitHub).
2. Envoyez `index.html`, `api.php` et `.htaccess` dans un dossier de votre hébergement, par exemple `public_html/in-house-dubbing/`.
3. Copiez `config.sample.php` en `config.php` dans le même dossier et remplacez `A_REMPLACER` par une longue suite de caractères au hasard. C'est la **clé d'installation**.
4. Ouvrez `https://votre-site/in-house-dubbing/?k=VOTRE_CLE_D_INSTALLATION`.
5. Créez votre **compte admin** : choisissez un identifiant et un mot de passe. Le lien avec `?k=` ne sert plus ensuite.

## 2. Régler les clés (facultatif, mais c'est là que l'appli devient magique)

- **Claude** (Accueil › **Réglages**) : prépare les projets à partir d'un PDF. Il écrit les résumés de séquences, les intentions de jeu et la direction de voix de chaque personnage. Clé à créer sur console.anthropic.com.
- **ElevenLabs** (dans un projet, onglet **Les voix**) : transforme chaque prise avec la voix choisie pour son personnage. Clé à créer sur elevenlabs.io, avec les permissions de lecture des voix et de voice changer.

Les clés restent sur votre serveur : elles ne sont jamais affichées, jamais envoyées aux navigateurs, et ne font pas partie du code. Les consommations sont décomptées sur vos comptes Claude et ElevenLabs.

## 3. Créer un projet à partir d'un scénario

1. Accueil › **+ Nouveau projet depuis un PDF**.
2. Choisissez le PDF, donnez un titre (l'identifiant se remplit tout seul) et cliquez **Analyser le PDF**. L'appli repère les scènes, les personnages et chaque réplique.
3. Avec une clé Claude, cliquez **Enrichir avec Claude** : découpage en séquences de travail, résumés, intentions, directions de voix, sons vocaux à enregistrer (cris, efforts…).
4. Vérifiez l'aperçu, puis **Créer le projet**.

Le PDF doit avoir la mise en page standard d'un scénario (en-têtes INT./EXT., noms des personnages centrés au-dessus des répliques) et contenir du texte, pas une image scannée.

## 4. Choisir les voix IA

Dans le projet, onglet **Les voix** : une voix ElevenLabs par personnage (▶ pour l'écouter). Cochez **Voix IA automatique après chaque prise** pour que chaque prise soit convertie dès son envoi. Si une voix ne convient pas, changez-la : le bouton **↻ Refaire ses prises** reconvertit tout à partir des originaux, qui ne sont jamais écrasés.

## 5. Inviter la personne qui fait les voix

1. Accueil › **Comptes** › **Créer un compte** : identifiant, mot de passe, et les projets qu'elle peut voir.
2. Envoyez-lui l'adresse (sans le `?k=`), son identifiant et son mot de passe.
3. Elle se connecte, choisit une séquence, clique sur un personnage sous le titre pour ne voir que ses répliques, et appuie sur **Rec** (ou Espace) en face de chaque ligne. Un casque est conseillé. Le navigateur demande l'autorisation du micro au premier enregistrement.

## 6. Récupérer les prises

Dans Chrome ou Edge, ouvrez le projet et cliquez **Transfert audio**, puis choisissez le dossier du projet sur votre ordinateur. Chaque séquence a son dossier :

- `SEQ01_…/SEQ01_L02_PERSONNAGE.wav` : la prise retenue (la version IA si elle existe) ;
- `SEQ01_…/_prises/` : toutes les prises originales et leurs versions IA, en sauvegarde.

Le transfert dépose aussi `In-House-Dubbing.html` : ouvert depuis le dossier, il permet de réécouter le projet hors ligne. Relancez le transfert quand vous voulez, seuls les fichiers nouveaux ou modifiés sont copiés.

## Bon à savoir

- **Sécurité** : mots de passe chiffrés, sessions de 30 jours, blocage après 8 essais ratés, aucun fichier audio accessible sans être connecté.
- **Sauvegarde** : tout est dans le dossier `data/` du serveur. Les prises supprimées vont dans `_corbeille`.
- **Mettre à jour l'appli** : remplacez `index.html`, `api.php` et `.htaccess` ; ne touchez ni à `config.php` ni à `data/`.
