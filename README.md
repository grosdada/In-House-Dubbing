# In-House-Dubbing

Outil web pour enregistrer des **voix maquettes** réplique par réplique, projet par projet, puis les transformer avec le **voice changer d'ElevenLabs** et les récupérer sur son PC pour l'animatique.

Une seule page HTML, qui fonctionne de deux façons :

- **Mode PHP** : avec `api.php` sur n'importe quel hébergement PHP 8. Aucune base de données, tout est stocké en fichiers dans `data/`.
- **Mode Supabase** : sans serveur. La page (hébergeable gratuitement) s'appuie sur un projet Supabase pour les comptes, la base et le stockage des prises, et sur une petite fonction (`supabase/functions/ihd`) qui garde les clés API. Il suffit de poser un `supabase-config.js` à côté de la page.

## Fonctions

- **Projets** : chaque projet est un scénario découpé en séquences et en répliques (texte, personnage, intention de jeu). Page d'accueil avec la progression de chaque projet.
- **Enregistrement** dans le navigateur, un bouton Rec par réplique (ou la touche Espace). WAV mono 16 bits, sans traitement. Toutes les prises sont gardées (P1, P2…), une est « retenue ».
- **Voix IA** : une voix ElevenLabs par personnage. Conversion d'une prise, d'une séquence entière, ou automatique après chaque prise. Les originaux ne sont jamais écrasés. Changer la voix d'un personnage permet de refaire toutes ses versions IA à partir des originaux.
- **Favori** : ★ sur la prise choisie de chaque réplique ; c'est elle que le transfert copie. Chaque nouvelle prise devient favorite ; ☆ sur une ancienne prise permet d'y revenir.
- **Gain** : clic droit sur une prise pour monter son niveau ou la normaliser, prise par prise ou pour tout un personnage ; appliqué à l'écoute et au transfert, l'original reste intact.
- **Interface FR / EN** : bouton de langue ; le contenu des projets n'est pas traduit.
- **Generate** : sans comédien, chaque réplique peut être lue directement par la voix IA de son personnage (ElevenLabs text to speech), une par une ou toute une séquence. Étiquette « IA voice ».
- **Transfert vers le PC** (Chrome ou Edge) : la prise retenue de chaque réplique arrive dans le dossier de sa séquence sous un nom simple (`SEQ01_L02_PRESIDENT.wav`), toutes les prises originales et IA dans `_prises/`. Le transfert dépose aussi une copie de la page et `_projet.js` pour écouter hors ligne.
- **Import d'un scénario PDF** : découpe automatique en séquences, personnages et répliques ; avec une clé Claude, résumés, intentions de jeu, directions de voix et sons vocaux.
- **Comptes** : un admin, des invités. L'admin crée les comptes, choisit les projets ouverts à chaque invité, gère la clé ElevenLabs et les réglages de conversion. Mots de passe hachés, sessions de 30 jours, blocage après 8 échecs.

Pour démarrer pas à pas, voir **[GUIDE.md](GUIDE.md)**.

## Installation (mode PHP)

Pour le mode Supabase, suivez la partie « B » de [GUIDE.md](GUIDE.md).

1. Copier `index.html`, `api.php` et `.htaccess` dans un dossier servi en HTTPS par PHP 8 (avec l'extension curl).
2. Copier `config.sample.php` en `config.php` et y mettre une `setup_key` longue et aléatoire.
3. Ouvrir `https://…/?k=VOTRE_SETUP_KEY` et créer le compte admin. Le lien ne sert plus ensuite.
4. Sur l'accueil, **Importer un projet** (fichier `.json`, voir ci-dessous).
5. Dans le projet, onglet **IA Voice selector** : coller la clé API ElevenLabs et choisir une voix par personnage.

Le dossier `data/` est créé automatiquement et interdit au web.

## Format d'un projet

Voir `examples/projet-exemple.json`.

- `id` : identifiant (minuscules, chiffres, tirets). Réimporter un fichier avec le même `id` met à jour le scénario sans toucher aux prises.
- `chars` : les personnages, clé en MAJUSCULES (`n` nom affiché, `c` couleur, `v` direction de voix).
- `seqs` : les séquences (`id` `SEQ01`…, `folder` nom du dossier sur le PC, `title`, `slug`, `pages`, `summary`, `lines`).
- Chaque réplique : `[numéro, personnage, type, texte, intention, page]`. Type : `""` réplique, `"v"` son vocal sans texte, `"a"` réplique adaptée.
- `nameVar` (facultatif) : un prénom réglable dans la page, inséré à la place de `{NAME}` dans les répliques.

Les scénarios sont confidentiels : ils s'importent sur le serveur et ne se versionnent pas (`projects/` est ignoré par git).
