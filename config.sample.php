<?php
// Copiez ce fichier en config.php sur le serveur (config.php n'est jamais versionné).
return [
  // Clé du lien d'installation : https://.../?k=VOTRE_CLE ne sert qu'à créer le premier compte admin.
  // Générez une valeur longue et aléatoire, par exemple avec : php -r "echo bin2hex(random_bytes(16));"
  'setup_key' => 'A_REMPLACER',

  // Facultatif : reprise d'une ancienne installation mono-projet (comptes, clé ElevenLabs, prises).
  // 'legacy' => ['project' => 'identifiant-du-projet', 'data' => '/chemin/absolu/vers/ancien/data'],
];
