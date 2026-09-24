# Salonerp — Campagnes de vote pour Dolibarr

Salonerp ajoute à Dolibarr un système de campagnes de vote à enveloppe cachée : un
groupe d'utilisateurs répartit un budget de points entre les tiers clients portant un
tag donné.

## Fonctionnalités de cette version

- Création d'une campagne de vote (libellé, description, dates, tag client, groupe de
  votants, budget de points par défaut).
- Synchronisation automatique des votants à partir des membres actifs du groupe choisi ;
  le créateur de la campagne fait toujours partie des votants.
- Liste des tiers de la campagne, tirée du tag client par synchronisation tant que la
  campagne est en brouillon. À la validation, elle est figée avec une photo de chaque
  tiers (nom, code client, code postal, ville) inscrite dans l'empreinte de genèse :
  ajouter ou retirer le tag, modifier ou supprimer un tiers ensuite ne change rien à la
  campagne, et la version d'origine reste consultable.
- Validation de la campagne : génération d'une paire de clés (chiffrement des votes des
  lots suivants) et d'une empreinte de genèse vérifiable, qui fige définitivement la
  campagne (dates, tag, groupe, votants, leurs points et tiers ne sont plus modifiables).
- La clé privée générée à la validation n'est affichée qu'une seule fois, à l'écran :
  elle n'est jamais enregistrée par Dolibarr (ni en base, ni en session, ni en fichier).
  Il appartient à l'utilisateur de la conserver en lieu sûr.

- Vote (onglet « Voter ») entre la date de début et la date de fin. Le responsable vote
  en premier, avec sa clé privée (vérifiée, jamais enregistrée) : son vote ouvre la
  campagne aux autres votants. Chaque votant répartit la totalité de ses points entre les
  tiers de la campagne, une seule fois, sans retour possible. Sans vote du responsable
  avant la fin, la campagne s'éteint et peut être supprimée.
- Votes secrets et chaînés : chaque vote est chiffré avec la clé publique de la campagne
  et porte l'empreinte du précédent, la première étant l'empreinte de genèse.
- Fichier des votes à télécharger, obligatoire avant de voter : la genèse en clair (toutes
  les informations publiques, nommées : votants et leurs points, tiers, responsable, tag,
  groupe) et tous les votes, chiffrés et chaînés, sans aucune identité en clair. Chacun
  peut y revérifier la chaîne, en cas de désaccord ou de contrôle.

- Révélation (onglet « Résultats »), après la date de fin : le responsable ressaisit sa
  clé privée. La chaîne est entièrement revérifiée et chaque vote est rapproché de son
  enregistrement avant tout déchiffrement ; au moindre écart, rien n'est modifié. La clé
  est ensuite enregistrée et affichée à tous : les votes deviennent publics.
- Dépouillement figé : pour chaque tiers, le votant qui y a misé le plus l'emporte ; un
  tiers sans point n'est attribué à personne. Égalités : plus forte part de son enveloppe,
  puis tirage vérifiable dérivé de l'empreinte finale de la chaîne. Chaque résultat
  affiche les votes reçus, la règle qui a tranché et son calcul.
- Attribution des commerciaux, une seule fois, par le responsable : chaque gagnant est
  ajouté comme commercial du tiers remporté, sans retirer les commerciaux en place.

Un prochain lot ajoutera un onglet de déchiffrement des fichiers de votes.

## Prérequis

- Dolibarr ERP & CRM version 21.0 ou supérieure.
- PHP 8.1 ou supérieur.
- L'extension PHP **sodium**, utilisée pour la génération des paires de clés. Sans
  elle, le module refuse de s'activer.

## Installation

1. Déposer le contenu de ce dépôt dans `htdocs/custom/salonerp` de votre installation
   Dolibarr.
2. Se connecter en tant qu'administrateur, aller dans **Configuration > Modules**.
3. Repérer le module **Votes** (famille CRM) et l'activer.

Le module crée cinq tables (`llx_salonerp_campaign`, `llx_salonerp_campaign_voter`,
`llx_salonerp_campaign_thirdparty`, `llx_salonerp_campaign_vote` et
`llx_salonerp_campaign_result`)
et trois permissions (lire, créer/modifier/valider, supprimer les campagnes) à
accorder aux utilisateurs concernés dans **Configuration > Utilisateurs & groupes**.

## Mise à jour

Après toute mise à jour du module (nouveau fichier, changement de version), **désactiver
puis réactiver le module** depuis Configuration > Modules : Dolibarr ne relit certaines
informations (droits, menus, structure des tables) qu'à l'activation.

## Licence

Ce module est distribué sous licence GPLv3 ou, à votre choix, toute version ultérieure.
Voir le fichier `LICENSE`.
