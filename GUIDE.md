# Guide de démarrage — In-House-Dubbing

In-House-Dubbing sert à faire enregistrer des **voix maquettes** à distance, réplique par réplique, à partir d'un scénario. La personne qui fait les voix reçoit un lien, enregistre dans son navigateur, et vous récupérez aussitôt les prises, transformées si vous le voulez avec les voix d'ElevenLabs.

## 1. Installer (une seule fois)

Deux façons d'installer l'appli. Choisissez **A** si vous avez déjà un hébergement web avec PHP, **B** si vous n'avez pas de serveur.

### A. Sur un hébergement PHP (10 minutes)

Il faut un hébergement web avec **PHP 8** et **HTTPS** (Hostinger, o2switch, OVH… dès 3 € par mois).

1. Téléchargez le dépôt (bouton **Code › Download ZIP** sur GitHub).
2. Envoyez `index.html`, `api.php` et `.htaccess` dans un dossier de votre hébergement, par exemple `public_html/in-house-dubbing/`.
3. Copiez `config.sample.php` en `config.php` dans le même dossier et remplacez `A_REMPLACER` par une longue suite de caractères au hasard. C'est la **clé d'installation**.
4. Ouvrez `https://votre-site/in-house-dubbing/?k=VOTRE_CLE_D_INSTALLATION`.
5. Créez votre **compte admin** : choisissez un identifiant et un mot de passe. Le lien avec `?k=` ne sert plus ensuite.

### B. Sans serveur, avec Supabase (20 minutes, gratuit pour commencer)

Supabase fournit les comptes, la base de données et le stockage des prises. La page elle-même peut être hébergée gratuitement (Netlify, GitHub Pages, Cloudflare Pages…).

1. **Créez un projet** sur [supabase.com](https://supabase.com) (bouton *New project*, choisissez une région proche de vous).
2. **La base de données** : menu *SQL Editor* › *New query*, collez tout le contenu de `supabase/schema.sql`, cliquez *Run*. Cela crée les tables, les règles d'accès et le stockage privé des prises.
3. **La fonction serveur** (elle garde les clés ElevenLabs et Claude) : menu *Edge Functions* › *Deploy a new function* › *Via Editor*. Nommez-la exactement `ihd`, remplacez le code d'exemple par le contenu de `supabase/functions/ihd/index.ts`, puis *Deploy function*.
   Avec la ligne de commande Supabase, c'est équivalent à `supabase functions deploy ihd`.
4. **Les comptes** : menu *Authentication* › *Sign In / Providers* › *Email*. Laissez *Confirm email* activé si vous voulez que chaque adresse soit vérifiée, ou désactivez-le pour aller plus vite. Dans *Authentication* › *URL Configuration*, mettez l'adresse où sera la page dans *Site URL*.
5. **La page** : copiez `supabase-config.sample.js` en `supabase-config.js` et remplissez les deux valeurs (*Project Settings* › *API* : *Project URL* et clé *anon public*). Mettez en ligne `index.html` et `supabase-config.js` ensemble, par exemple en glissant le dossier sur [app.netlify.com/drop](https://app.netlify.com/drop).
6. **Ouvrez la page** et cliquez **Créer un compte** : le tout premier compte devient **admin**.

Différences avec l'installation PHP : les collaborateurs créent eux-mêmes leur compte sur la page de connexion, puis l'admin les **valide** dans *Comptes* et coche leurs projets ; un mot de passe oublié se récupère par un lien envoyé par e-mail.

## 2. Régler les clés (facultatif, mais c'est là que l'appli devient magique)

- **Claude** (Accueil › **Réglages**) : prépare les projets à partir d'un PDF. Il écrit les résumés de séquences, les intentions de jeu et la direction de voix de chaque personnage. Clé à créer sur console.anthropic.com.
- **ElevenLabs** (dans un projet, onglet **IA Voice selector**) : transforme chaque prise avec la voix choisie pour son personnage. Le bouton **Generate** fait aussi lire une réplique directement par cette voix, à partir du texte. Clé à créer sur elevenlabs.io, avec les permissions de lecture des voix, de voice changer et de text to speech.

Les clés restent sur votre serveur : elles ne sont jamais affichées, jamais envoyées aux navigateurs, et ne font pas partie du code. Les consommations sont décomptées sur vos comptes Claude et ElevenLabs.

## 3. Créer un projet à partir d'un scénario

1. Accueil (ou la boule jaune en haut à gauche) › **+ Add new project**.
2. Choisissez le PDF, donnez un titre (l'identifiant se remplit tout seul) et cliquez **Analyser le PDF**. L'appli repère les scènes, les personnages et chaque réplique.
3. Avec une clé Claude, cliquez **Enrichir avec Claude** : découpage en séquences de travail, résumés, intentions, directions de voix, sons vocaux à enregistrer (cris, efforts…).
4. Vérifiez l'aperçu, puis **Créer le projet**.

Le PDF doit avoir la mise en page standard d'un scénario (en-têtes INT./EXT., noms des personnages centrés au-dessus des répliques) et contenir du texte, pas une image scannée.

## 4. Choisir les voix IA

Dans le projet, onglet **IA Voice selector** : une voix ElevenLabs par personnage (▶ pour l'écouter). Cochez **Voix IA automatique après chaque prise** pour que chaque prise soit convertie dès son envoi. Si une voix ne convient pas, changez-la : le bouton **↻ Refaire ses prises** reconvertit tout à partir des originaux, qui ne sont jamais écrasés.

Pas encore de comédien ? Le bouton **Generate**, à côté de **Rec**, fait lire la réplique par la voix IA du personnage, à partir du texte du scénario (text to speech). **✦ Generate la séquence** fait de même pour toutes les répliques de la séquence qui n'ont encore aucune prise. Ces générations portent l'étiquette **IA voice** et partent au transfert comme les autres.

Favori : la prise marquée ★ (en or) est celle qui part au transfert. Par défaut c'est la plus récente ; cliquez ☆ sur une autre pour la choisir. Un favori choisi à la main reste en place même si d'autres prises arrivent ensuite, et le transfert remplace le fichier sur le PC quand le favori change.

Langue : le bouton **FR | EN** en haut à droite passe toute l'interface en anglais (ou en français). Le contenu des projets n'est pas traduit. Les sons vocaux (rires, souffles) ne se génèrent pas : ils s'enregistrent. Le modèle de génération se choisit dans **IA Voice selector** (Multilingue v2, v3 plus expressif, Flash v2.5 rapide).

## 5. Inviter la personne qui fait les voix

1. *Installation PHP* : Accueil › **Comptes** › **Créer un compte** : identifiant, mot de passe, et les projets qu'elle peut voir. Envoyez-lui l'adresse (sans le `?k=`), son identifiant et son mot de passe.
2. *Installation Supabase* : envoyez-lui l'adresse de la page. Elle clique **Créer un compte** ; vous la **validez** dans Accueil › Comptes et cochez ses projets.
3. Elle se connecte, choisit une séquence, clique sur un personnage sous le titre pour ne voir que ses répliques, et appuie sur **Rec** (ou Espace) en face de chaque ligne. Un casque est conseillé. Le navigateur demande l'autorisation du micro au premier enregistrement.

## 6. Récupérer les prises

Dans Chrome ou Edge, ouvrez le projet et cliquez **Transfert audio**, puis choisissez le dossier du projet sur votre ordinateur. Chaque séquence a son dossier :

- `SEQ01_…/SEQ01_L02_PERSONNAGE.wav` : la prise retenue (la version IA si elle existe) ;
- `SEQ01_…/_prises/` : toutes les prises originales et leurs versions IA, en sauvegarde.

Le transfert dépose aussi `In-House-Dubbing.html` : ouvert depuis le dossier, il permet de réécouter le projet hors ligne. Relancez le transfert quand vous voulez, seuls les fichiers nouveaux ou modifiés sont copiés.

## Bon à savoir

- **Sécurité** : mots de passe chiffrés, sessions de 30 jours, blocage après 8 essais ratés, aucun fichier audio accessible sans être connecté.
- **Sauvegarde** : en PHP, tout est dans le dossier `data/` du serveur ; avec Supabase, dans votre projet Supabase (tables et stockage `takes`). Les prises supprimées vont dans `_corbeille`.
- **Mettre à jour l'appli** : en PHP, remplacez `index.html`, `api.php` et `.htaccess` sans toucher à `config.php` ni à `data/` ; avec Supabase, remplacez `index.html` (et la fonction `ihd` si elle a changé), sans toucher à `supabase-config.js`.
