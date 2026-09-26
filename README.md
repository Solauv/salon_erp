# Salonerp — Campagnes de vote à enveloppe cachée pour Dolibarr

Salonerp répartit des tiers clients entre les commerciaux par un **vote à enveloppe
cachée** : chaque votant dispose d'un budget de points qu'il mise, en secret, sur les
tiers qu'il veut obtenir. À la fin, chaque tiers revient à celui qui y a misé le plus, et
il est ajouté comme commercial de ce tiers.

Tout ce qui est public est affiché et vérifiable ; les votes restent secrets jusqu'au
dépouillement, puis deviennent publics. Chaque vote est chiffré et chaîné aux autres :
personne, ni un votant, ni l'administrateur de la base, ne peut modifier, retirer ou
ajouter un vote sans que cela se voie.

- **Partie 1 — [Guide utilisateur](#partie-1--guide-utilisateur)** : le déroulé d'une
  campagne, qui fait quoi, ce qui est public ou secret.
- **Partie 2 — [Documentation technique](#partie-2--documentation-technique)** :
  installation, modèle de données, cryptographie, formats, sécurité.

---

# Partie 1 — Guide utilisateur

## Les rôles

| Rôle | Qui | Ce qu'il fait |
|---|---|---|
| **Responsable** | le créateur de la campagne | prépare la campagne, la valide, **vote en premier**, révèle les votes à la fin, attribue les commerciaux |
| **Votant** | chaque membre actif du groupe choisi, et toujours le responsable | vote une fois, en répartissant tous ses points |
| **Lecteur** | tout utilisateur ayant le droit « Consulter les campagnes » | suit la campagne, consulte les résultats, vérifie un fichier des votes |

Le menu **Votes** donne accès à la liste des campagnes, à la création d'une campagne et
au **déchiffrement externe**.

## Le déroulé d'une campagne

```
Brouillon ──Valider──▶ Validée ──le responsable vote──▶ Vote ouvert ──date de fin──▶ Vote terminé ──Révéler──▶ Révélée
                          │
                          └──date de fin sans vote du responsable──▶ Éteinte (supprimable)
```

### 1. Préparer la campagne (Brouillon)

Menu **Votes → Nouvelle campagne** : référence, libellé, dates et heures de **début** et
de **fin** du vote, **tag client** (les tiers concernés), **groupe des votants**, et
points par défaut de chaque votant (1 si le champ reste vide).

Sur la fiche de la campagne :

- **Votants** — « Synchroniser avec le groupe » ajoute les membres actifs du groupe (le
  responsable en fait toujours partie) ; les points de chacun se modifient dans le
  tableau, puis « Enregistrer les points ». Un votant a au moins 1 point.
- **Tiers de la campagne** — « Synchroniser avec le tag » reprend les tiers portant le
  tag client. Si le tag de la campagne change, la liste est vidée : il faut
  resynchroniser.

Tant que la campagne est en brouillon, tout reste modifiable et elle peut être supprimée.

### 2. Valider — et conserver la clé privée

Seul le responsable peut valider. La validation **fige tout** : dates, tag, groupe,
votants et leurs points, liste des tiers et notes. Elle photographie aussi chaque tiers
(nom, code client, code postal, ville) : la campagne garde cette version d'origine même
si le tiers est modifié ou supprimé ensuite. Ajouter ou retirer le tag d'un tiers après
la validation n'a plus aucun effet.

La validation génère une **clé privée**, affichée **une seule fois**. Le fichier de clé
(`.key`) est **téléchargé automatiquement** au chargement de la page ; si le navigateur
bloque ce téléchargement, les boutons « Copier » et « Télécharger (.key) » restent
disponibles.

> **Conservez-la immédiatement.** Dolibarr ne la garde nulle part. Sans elle, le
> responsable ne peut ni ouvrir le vote ni révéler les résultats, et **aucune
> procédure ne permet de la retrouver**.

### 3. Voter (onglet « Voter »)

Le vote n'est possible qu'entre la date de début et la date de fin.

1. **Le responsable vote en premier**, en saisissant sa clé privée : c'est son vote qui
   ouvre la campagne aux autres votants. S'il n'a pas voté à la date de fin, la campagne
   passe **Éteinte** et peut être supprimée.
2. Chaque votant **télécharge le fichier des votes** (obligatoire avant de voter) : il
   contient toutes les informations publiques de la campagne et tous les votes émis à ce
   jour, chiffrés, sans aucun nom.
3. Il **répartit la totalité de ses points** entre les tiers. Un compteur affiche les
   points restants, et le filtre « Uniquement les tiers sélectionnés » réduit la liste.
   Le vote n'est accepté que si le compte tombe juste.
4. Il lit l'avertissement « **vote définitif** », tape « confirmer » et vote. Un vote ne
   peut être ni modifié ni refait. Il peut ensuite retélécharger le fichier, qui contient
   son vote.
5. Son **reçu de vote** s'affiche, une seule fois : le fichier (`recu-<ref>-<seq>.json`)
   est **téléchargé automatiquement**, et les boutons « Copier » et « Télécharger mon
   reçu » restent disponibles si le navigateur a bloqué ce téléchargement. C'est sa seule
   preuve du contenu exact de son vote : conservez-le immédiatement, et voyez « Contester
   un vote » ci-dessous.

Un votant qui ne vote pas perd ses points.

### 4. Révéler les votes (onglet « Résultats »)

Après la date de fin, le responsable ressaisit sa clé privée. Avant de déchiffrer quoi
que ce soit, le module **revérifie toute la chaîne des votes** et contrôle que chaque
vote correspond bien à son enregistrement. Au moindre écart, la révélation est refusée
et rien n'est modifié.

Une fois les votes révélés, la **clé privée est affichée à tous** : les votes étant
devenus publics, chacun peut ainsi déchiffrer son propre fichier et constater que les
votes affichés sont bien ceux de la chaîne.

### 5. Lire les résultats

Pour chaque tiers, l'onglet « Résultats » affiche les **votes reçus**, le **gagnant**, sa
mise, la **règle qui a tranché** et le calcul correspondant. Le filtre « Uniquement les
tiers que j'ai remportés » réduit la liste à vos tiers. Sous le tableau, tous les votes
sont affichés en clair : votant, date et répartition.

Les règles, fixées à la validation et inscrites dans l'empreinte de la campagne :

| Situation | Règle |
|---|---|
| Aucun point sur le tiers | Personne ne l'obtient |
| Une seule plus forte mise | Elle l'emporte |
| Égalité sur la plus forte mise | **B — part de l'enveloppe** : l'emporte celui pour qui cette mise pèse le plus dans son budget (points misés ÷ points attribués) |
| Égalité persistante | **E — tirage vérifiable** : pour chaque votant encore à égalité, `sha256(empreinte finale de la chaîne:id du tiers:id du votant)` ; la plus petite empreinte l'emporte. Personne ne pouvait la prévoir : l'empreinte finale n'existe qu'après le dernier vote. |

### 6. Attribuer les commerciaux

Le responsable relit les résultats puis clique **« Attribuer les commerciaux »** (une
seule fois). Chaque gagnant est **ajouté** comme commercial du tiers remporté ; **aucun
commercial en place n'est retiré**. Un compte rendu indique les commerciaux ajoutés,
ceux déjà en place et les tiers supprimés entre-temps, qui sont ignorés.

### 7. Vérifier ou déchiffrer un fichier des votes

Le fichier des votes sert en cas de désaccord ou de contrôle. Trois façons de l'exploiter :

- **Onglet « Déchiffrer » de la campagne** (dès la validation) : déposez un fichier des
  votes, un reçu, ou les deux (au moins l'un des deux est requis). Un fichier déposé est
  vérifié : appartenance à la campagne, intégrité, correspondance avec la chaîne
  officielle — s'il diverge, le rapport indique **le numéro du vote où les chaînes se
  séparent**. Une fois les votes révélés, il déchiffre aussi le fichier. Un reçu déposé
  sans fichier des votes est vérifié directement contre la **chaîne officielle de cette
  instance**, reconstruite côté serveur pour l'occasion : voir aussi « Contester un vote »
  ci-dessous.
- **Menu Votes → Déchiffrement externe** : le fichier suffit ; la clé révélée et un reçu
  de vote sont tous deux facultatifs, et rien n'est lu dans la base. Utilisable pour
  n'importe quelle campagne, y compris depuis une autre instance de Dolibarr où le module
  est installé — voir aussi « Contester un vote » ci-dessous.
- **Sans Dolibarr** : la page de déchiffrement externe fournit un script PHP autonome et
  sa procédure (`php dechiffrer-votes.php FICHIER.json CLÉ_PRIVÉE`).

Le fichier déposé, comme le reçu s'il y en a un, est lu puis oublié : ni l'un ni l'autre
n'est jamais conservé sur le serveur.

## Contester un vote

Chaque vote donne lieu, une seule fois, à un **reçu** remis au votant au moment où il
vote (onglet « Voter »). Ce reçu prouve le contenu exact de son vote — **même si le code
du serveur a été modifié** par un administrateur malveillant — et personne, pas même le
votant lui-même, ne peut fabriquer un reçu valide pour un autre contenu que celui
réellement voté.

**Un premier contrôle, immédiat** : l'onglet **« Déchiffrer »** de cette campagne accepte
le reçu **seul**, sans fichier des votes à fournir — il est vérifié directement contre la
**chaîne officielle de cette instance**, reconstruite côté serveur pour l'occasion. Pratique
pour se rassurer tout de suite après un vote, mais ce n'est **pas une preuve
indépendante** : le serveur qui répond est celui-là même dont on ne veut, en cas de
litige, pas avoir à dépendre.

**La règle du litige** : pendant que le vote est ouvert, vérifiez donc aussi votre reçu sur
le **Déchiffrement externe** (menu Votes) **d'une autre instance Dolibarr**, avec le
fichier des votes de cette campagne. Deux issues possibles :

- **le reçu correspond** au vote enregistré : la preuve est faite, rien à faire ;
- **le reçu ne correspond pas** : signalez-le **avant la clôture du vote**. Passé ce
  délai, il sera trop tard pour agir dessus.

Un reçu qui ne correspond pas ne prouve rien en soi — ni contre le reçu, ni contre le
fichier pris isolément — mais son signalement, pendant que le vote est encore ouvert, est
ce qui permet de trancher. C'est pour cela que la vérification qui fait foi se fait sur
une **autre** instance que celle qui a servi à voter : elle ne dépend d'aucune clé de
campagne et fonctionne sans jamais faire confiance au serveur qui a émis le reçu — à
l'inverse du contrôle de l'onglet « Déchiffrer », qui reste un contrôle **de** ce serveur.

**Le fichier des votes doit être authentique.** Un faux reçu peut s'accompagner d'un faux
fichier des votes, cohérent avec lui : il suffit de rechiffrer un autre vote et de
recalculer la chaîne. Une instance qui ne voit que ces deux fichiers ne peut pas faire la
différence. En cas de litige, on vérifie donc le reçu contre un fichier des votes que
**l'on a téléchargé soi-même** sur l'instance de la campagne, ou dont l'**empreinte
finale**, affichée par le rapport, est la même que celle d'autres votants. Jamais contre
le seul fichier fourni par celui qui conteste.

Le reçu se vérifie de trois façons, toutes sans aucune clé de campagne :

- **Onglet « Déchiffrer » de cette campagne** : déposez seulement le reçu (voir
  ci-dessus) — un contrôle rapide, mais pas indépendant du serveur.
- **Menu Votes → Déchiffrement externe, d'une autre instance** : déposez le fichier des
  votes et le reçu (les deux facultatifs sauf le fichier des votes) ; le rapport indique
  si le reçu correspond, et affiche le vote en clair (votant, date, répartition) quand
  c'est le cas. C'est la vérification qui fait foi en cas de litige.
- **Sans Dolibarr**, avec le script autonome fourni sur cette page :
  `php dechiffrer-votes.php FICHIER.json --recu RECU.json`.

Perdu ou jamais téléchargé, un reçu ne peut être obtenu une seconde fois : Dolibarr ne le
conserve nulle part.

## Ce qui est public, ce qui est secret

| Information | Pendant le vote | Après la révélation |
|---|---|---|
| Votants, leur enveloppe de points, tiers, dates, responsable, tag, groupe | public (fiche et fichier des votes) | public |
| Nombre de votes émis | public | public |
| Qui a voté, quand, et pour quels tiers | **secret** (chiffré) | public |
| Clé privée | détenue par le responsable seul | publique |
| Reçu d'un vote | détenu par le votant concerné seul, remis une seule fois | idem (son contenu redevient lisible en clair pour tous, comme le vote lui-même) |

Pendant le vote, l'onglet « Voter » ne vous affiche que votre propre statut (« Vous avez
voté le… ») : personne ne voit qui a déjà voté.

## Questions fréquentes

- **J'ai perdu la clé privée.** Elle n'existe nulle part ailleurs : la campagne ne peut
  plus être ouverte ni dépouillée. Si le vote n'a pas été ouvert, la campagne s'éteindra à
  sa date de fin et pourra être supprimée.
- **Un tiers a été renommé ou supprimé pendant la campagne.** La campagne garde sa version
  d'origine ; la fiche indique le nom actuel entre parenthèses, ou la suppression.
- **Qui peut modifier une campagne ?** Tant qu'elle est en brouillon, tout utilisateur
  ayant le droit « Créer/modifier/valider » ; seul le responsable peut la valider. Après
  la validation, plus personne.

---

# Partie 2 — Documentation technique

## Prérequis, installation, mise à jour

- Dolibarr 21.0 ou supérieur (développé et recetté sur **24.0.1**).
- PHP 8.1 ou supérieur, avec l'extension **sodium** (le module refuse de s'activer sans).

**Installation** : déposer le contenu de ce dépôt dans `htdocs/custom/salonerp`, puis
Configuration → Modules → **Votes** (famille CRM) → activer. Accorder ensuite les droits
dans Configuration → Utilisateurs & groupes.

**Mise à jour** : après tout remplacement des fichiers, **désactiver puis réactiver** le
module. Dolibarr ne relit le descripteur (droits, menus) et ne crée les nouvelles tables
qu'à l'activation. L'activation ne modifie pas une table existante : une colonne ajoutée
entre deux versions doit être migrée à part.

## Droits

| Droit | Code | Donne accès à |
|---|---|---|
| Consulter les campagnes | `salonerp campaign read` | menus, fiches, onglets Voter / Résultats / Déchiffrer, déchiffrement externe |
| Créer/modifier/valider | `salonerp campaign write` | création, modification des brouillons, synchronisations ; validation par le seul créateur |
| Supprimer | `salonerp campaign delete` | suppression (brouillon ou éteinte uniquement) |

Le **droit de voter** n'est pas une permission : il faut figurer dans la liste des votants
de la campagne (et avoir le droit de consultation). Révélation et attribution sont
réservées au créateur de la campagne.

## Fichiers

| Fichier | Rôle |
|---|---|
| `core/modules/modSalonerp.class.php` | descripteur : droits, menus, contrôle de sodium à l'activation |
| `class/campaign.class.php` | objet `Campaign` : cycle de vie, verrou, genèse, vote, révélation, dépouillement, attribution |
| `class/campaignvoter.class.php` | objet `CampaignVoter` : un votant et son enveloppe |
| `class/salonerpvotefile.class.php` | `SalonerpVoteFile` : analyse d'un fichier des votes et vérification d'un reçu, **sans accès à la base** |
| `campaign_card.php`, `campaign_list.php`, `campaign_note.php` | fiche, liste, notes |
| `campaign_vote.php` | onglet Voter : fichier des votes, formulaire de vote, reçu affiché une fois |
| `campaign_result.php` | onglet Résultats : révélation, résultats, attribution |
| `campaign_decrypt.php` | onglet Déchiffrer : contrôle d'un fichier contre la chaîne officielle, ou d'un reçu seul contre elle |
| `decrypt_external.php` | déchiffrement externe, vérification d'un reçu, script autonome |
| `lib/salonerp_campaign.lib.php` | onglets, contrôle CSRF, lecture du fichier/reçu déposé, rendu du rapport, script autonome |
| `sql/` | tables et clés |
| `langs/fr_FR`, `langs/en_US` | traductions (mêmes clés dans les deux langues) |

## Modèle de données

| Table | Contenu |
|---|---|
| `llx_salonerp_campaign` | la campagne : dates, tag, groupe, `public_key`, `tie_rule`, `genesis_payload` et `genesis_hash` (figés à la validation), `private_key` et `date_reveal` (renseignés à la révélation), `date_attribution`, `status` |
| `llx_salonerp_campaign_voter` | votants et enveloppes ; unique (campagne, utilisateur) |
| `llx_salonerp_campaign_thirdparty` | liste figée des tiers et leur photo (`name`, `code_client`, `zip`, `town`, remplis à la validation) ; **sans clé étrangère vers `llx_societe`**, pour qu'une suppression de tiers n'altère pas la liste |
| `llx_salonerp_campaign_vote` | la chaîne : `seq`, `fk_user`, `ciphertext`, `prev_hash`, `hash` ; uniques (campagne, utilisateur) et (campagne, rang) ; clé étrangère **sans cascade** |
| `llx_salonerp_campaign_result` | résultat figé par tiers : gagnant, mise, règle (`none`, `max`, `B`, `E`), détail du calcul en JSON ; clé étrangère sans cascade |

`fk_user` est conservé en base dans la table des votes, pour interdire un second vote. Il
n'apparaît jamais en clair dans le fichier des votes, ni dans l'interface avant la
révélation.

## Statuts et transitions

| Code | Statut | Entrée | Par |
|---|---|---|---|
| 0 | Brouillon | création | — |
| 1 | Validée | `validate()` | créateur |
| 2 | Vote ouvert | premier `castVote()` réussi | créateur, avec sa clé |
| 3 | Vote terminé | date de fin atteinte, vote ouvert | horloge |
| 4 | Révélée | `reveal()` | créateur, avec sa clé |
| 8 | Éteinte | date de fin atteinte, statut Validée | horloge |

Les transitions liées à l'horloge sont appliquées par `Campaign::refreshTimeStatus()` à
l'ouverture de la fiche et des onglets Voter, Résultats et Déchiffrer (aucune tâche
planifiée). Elle est idempotente :
chaque `UPDATE` ne porte que sur le statut attendu. `castVote()` ne s'y fie pas et
contrôle lui-même la fenêtre de vote.

## Verrouillage et transactions

Le statut est une frontière de sécurité : il n'est **jamais** lu depuis l'objet en
mémoire. Toute opération qui modifie ce que la validation fige, ou qui ajoute à la chaîne,
ouvre une transaction et appelle `lockAndFetchStatus()` (`SELECT … FOR UPDATE` sur la
ligne de la campagne, relecture du statut). C'est le cas de `validate()`,
`syncVotersFromGroup()`, `saveVoterPoints()`, `syncThirdpartiesFromCategory()`,
`castVote()`, `reveal()` et `attributeSalesReps()`. Deux opérations sur une même
campagne sont donc strictement séquentielles : aucune synchronisation ne peut se glisser
entre la lecture et l'écriture de la validation, et deux votes ne peuvent pas se
disputer le même rang.

## Cryptographie

- **Paire de clés** X25519 générée par libsodium (`sodium_crypto_box_keypair`) à la
  validation ; clés en base64. La clé publique est stockée ; la clé privée n'est renvoyée
  qu'à l'appelant, affichée une fois, puis effacée de la mémoire (`sodium_memzero`).
- **Chiffrement des votes** : *sealed box* libsodium — mais **reconstruite à la main**
  par `Campaign::sealBallot()` plutôt que produite par `sodium_crypto_box_seal()`, pour
  garder ce que cette dernière jette : la clé éphémère. Voir « Le reçu de vote ».
- **Bourrage à taille fixe** : avant scellement, le bulletin en clair est bourré
  (`sodium_pad`) à une taille fixe par campagne, `ballot_size`, inscrite dans la genèse.
  Tous les chiffrés d'une même campagne ont ainsi exactement la même longueur : le
  fichier des votes, téléchargé par tous pendant le vote, ne fuit ni le nombre de tiers
  misés, ni la taille des mises, ni l'identité du votant. Voir « La genèse » et « Les
  votes et la chaîne ».
- **Vérification de la clé ressaisie** : la clé publique est dérivée de la clé privée
  proposée et comparée à celle stockée, en temps constant (`hash_equals`).
- **Empreintes** : SHA-256, en hexadécimal minuscule.

## La genèse

À la validation, `getGenesisPayload()` produit un JSON **canonique** : clés triées,
dates en UTC ISO 8601 via `gmdate()` (indépendant du fuseau PHP), listes triées par id,
types fixes, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Ce JSON est stocké tel
quel (`genesis_payload`) et `genesis_hash = sha256(genesis_payload)`. Les vérifications
hachent la chaîne stockée ; elles ne la reconstruisent jamais.

Toute information publique y figure **nommée**, jamais par un id seul :

```json
{
  "ballot_size": 214,
  "category": {"id": 538, "label": "Salon 2026"},
  "creator": {"id": 535, "name": "Alice Recette"},
  "date_end": "2026-09-26T16:00:00Z",
  "date_start": "2026-09-24T06:00:00Z",
  "entity": 1,
  "group": {"id": 539, "name": "Votants salon"},
  "label": "Salon 2026",
  "public_key": "…base64…",
  "ref": "SALON-2026",
  "thirdparties": [{"code_client": "CU2609-00001", "id": 427, "name": "Atelier Bois Martin", "town": "Le Puy", "zip": "43000"}],
  "tie_rule": "B_E",
  "voters": [{"id": 535, "name": "Alice Recette", "points": 100}]
}
```

`ballot_size` fixe, en octets, la taille à laquelle chaque bulletin en clair est bourré
avant chiffrement (voir « Les votes et la chaîne »). Calculée à la validation par
`Campaign::computeBallotSize()`, sur le pire cas — un vrai majorant, jamais reconstruit
ensuite : *tous* les tiers de la liste figée misés à la fois, chacun à la plus grande
enveloppe de points parmi les votants, par le votant dont l'id est le plus grand, au rang
le plus élevé atteignable (le nombre de votants). `getGenesisPayload()` reçoit cette
taille toute faite ; elle ne recalcule rien. Aucune campagne validée sans `ballot_size`
n'est utilisable : voir « Sécurité ».

## Les votes et la chaîne

Contenu en clair d'un vote (JSON), produit par `Campaign::buildBallotPlaintext()`, seul
endroit qui construit cette chaîne — `castVote()` l'appelle pour le vrai bulletin,
`computeBallotSize()` pour le pire cas de la campagne, afin qu'ils ne divergent jamais :

```json
{"campaign": "SALON-2026", "date": "2026-09-24T08:04:13Z", "fk_user": 535, "points": [[427, 50], [428, 50]], "seq": 1}
```

Les tiers à 0 point sont retirés du bulletin avant encodage. Avant scellement
(`sodium_crypto_box_seal`), ce JSON est bourré à taille fixe : `sodium_pad($clair,
$ballot_size)`, où `$ballot_size` est relu tel quel dans la genèse stockée de la
campagne, jamais recalculé. Toutes les longueurs de bulletin étant inférieures au pire
cas qui a servi à fixer `ballot_size` (garde-fou : `castVote()` refuse sinon, ce qui ne
doit jamais arriver), le bourrage porte toujours le clair à exactement `ballot_size`
octets : deux votes d'une même campagne, si différents soient leurs bulletins, produisent
des chiffrés de longueur identique. Le clair bourré est effacé de la mémoire
(`sodium_memzero`) aussitôt après scellement ; le clair non bourré est conservé un
instant de plus, le temps de construire le reçu du votant (voir « Le reçu de vote »
ci-dessous), puis effacé à son tour.

Chaque vote est ensuite chaîné :

```
hash(n)      = sha256('{"ciphertext":"…","prev_hash":"…","seq":n}')   (clés dans cet ordre, sans espace, slashes non échappés)
prev_hash(1) = genesis_hash
prev_hash(n) = hash(n-1)
```

`Campaign::verifyChain()` vérifie la genèse, la continuité des rangs, chaque lien et
chaque empreinte. Modifier, retirer, réordonner ou insérer un vote casse la chaîne à
partir du vote touché.

Contrôles de `castVote()` : fenêtre de vote, votant de la campagne, pas de second vote,
premier vote réservé au créateur avec une clé valide, tiers de la liste figée
uniquement, entiers positifs ou nuls, total égal à l'enveloppe.

## Le reçu de vote

Un reçu de vote est la **clé de litige** de chaque vote : la preuve que le votant peut
opposer au contenu de son propre vote, même si le code servi par le serveur au moment de
la révélation a été modifié. Il est construit par `Campaign::buildReceipt()`, une seule
fois, à l'instant même de `castVote()`, et n'est **jamais reconstructible après coup** :
rien de ce qu'il contient n'est conservé par le module une fois la réponse envoyée.

**Le principe.** `sodium_crypto_box_seal($clair, $publicKey)` génère en interne une paire
de clés éphémères, chiffre avec, puis **jette la moitié privée** (`eph_sk`) : c'est ce qui
rend une sealed box normale non réexplicable après coup, y compris pour son auteur.
`Campaign::sealBallot()` reconstruit exactement le même calcul à la main, mais en
recevant `eph_sk` en paramètre au lieu de le générer et de le perdre :

```
eph_pk      = derivee_publique(eph_sk)
nonce       = BLAKE2b(eph_pk . cle_publique_campagne, 24 octets)
chiffre     = eph_pk . crypto_box(clair_bourre, nonce, eph_sk, cle_publique_campagne)
```

`castVote()` tire un `eph_sk` neuf (`sodium_crypto_box_keypair()`) à **chaque vote**, appelle
`sealBallot()` pour produire exactement le même chiffré que produirait
`sodium_crypto_box_seal()` — ouvrable sans changement par `sodium_crypto_box_seal_open()` —
puis, au lieu de le jeter, remet `eph_sk` au votant dans son reçu. **`eph_sk` n'est stocké
nulle part côté serveur** : ni dans `llx_salonerp_campaign_vote`, ni dans aucune autre
table, ni en session, ni dans un journal, ni dans un fichier ; il ne vit que dans les
variables locales de cet appel, effacées (`sodium_memzero`) sitôt le reçu construit.

**Pourquoi ça ne se forge pas.** Un chiffré sealed box ne s'ouvre jamais qu'en un seul
clair. Avec `eph_sk`, la clé publique de la genèse et `ballot_size`, n'importe qui
recalcule le chiffré à l'octet près et le compare à celui du même rang dans le fichier
des votes : une correspondance exacte est une preuve, qu'aucun contenu différent n'aurait
pu produire — ni le votant, ni l'administrateur du serveur, ni personne d'autre. Cette
preuve suppose un fichier des votes authentique : le verdict n'est rendu que si le fichier
est intègre (genèse, taille des bulletins, chaîne) et si le vote de ce rang porte
l'empreinte `hash` du reçu, mais un fichier entièrement refabriqué reste cohérent ; le
rapport affiche donc l'empreinte finale du fichier, à comparer avec une copie de confiance.

**Format** `salonerp-receipt/1`, JSON :

| Clé | Contenu |
|---|---|
| `format`, `campaign`, `genesis_hash`, `seq` | identification du vote |
| `hash` | empreinte de chaîne du vote (`Campaign::computeVoteHash()`) |
| `ballot` | le bulletin en clair **exact, non bourré** : la chaîne JSON telle que chiffrée après débourrage |
| `ephemeral_key` | `eph_sk`, en base64 |
| `how_to_verify` | la procédure de vérification, en clair |
| `ballot_decoded` | le même bulletin, décodé et nommé (votant, tiers), pour la lecture humaine — **hors de ce qui est vérifié** |

Téléchargé côté client (bouton « Télécharger mon reçu » de l'onglet Voter), sans second
aller-retour serveur, exactement comme la clé privée à la validation : nom de fichier
`recu-<ref>-<seq>.json`, page servie avec `Cache-Control: no-store`.

## Le fichier des votes

Produit par `Campaign::buildVoteFile()`, format `salonerp-votes/1` :

| Clé | Contenu |
|---|---|
| `format`, `campaign`, `generated_at` | identification |
| `how_to_verify` | la procédure de vérification, en clair |
| `genesis_hash`, `genesis_payload` | la genèse exacte, à hacher telle quelle |
| `genesis` | la même genèse décodée, pour la lecture |
| `tie_rule_explanation` | la règle d'égalité en toutes lettres (hors genèse : ce texte dépend de la langue) |
| `votes` | la chaîne : `{seq, prev_hash, ciphertext, hash}` par vote, **aucune identité en clair** |
| `last_hash` | empreinte finale de la chaîne |

Le téléchargement est réservé aux votants et est exigé (en session) avant de voter.
`genesis_payload` porte `ballot_size` : c'est la seule source de cette taille pour qui
ouvre le fichier, elle n'est jamais recalculée par un outil de déchiffrement.

## Révélation et dépouillement

`reveal()`, sous verrou, dans cet ordre : statut « Vote terminé », créateur, clé valide,
`ballot_size` présent dans la genèse stockée (sinon refus immédiat : la genèse n'est pas
conforme), `verifyChain()` sur toute la chaîne, ouverture
de chaque vote et **rapprochement avec sa ligne** (même rang, même votant, même campagne
— ce qui détecte un `fk_user` échangé en base, invisible pour la chaîne). À l'ouverture,
après `sodium_crypto_box_seal_open()`, le clair obtenu doit faire exactement
`ballot_size` octets et `sodium_unpad()` doit réussir ; tout écart est traité comme un
vote illisible, révélation refusée. Seulement si tout tient : calcul, écriture des
résultats, stockage de la clé et passage au statut « Révélée ». Toute erreur annule la
transaction.

`Campaign::computeResults()` est une fonction pure (aucun accès à la base) : plus forte
mise ; puis règle B, comparée en entiers exacts (`a₁ × e₂` contre `a₂ × e₁`) ; puis
règle E, `sha256(last_hash . ':' . id_tiers . ':' . id_votant)`, plus petite empreinte en
ordre de chaîne hexadécimale. Les enveloppes viennent de la table des votants, figée
depuis la validation.

## Attribution des commerciaux

`attributeSalesReps()` : créateur, statut « Révélée », une seule fois
(`date_attribution`). Pour chaque gagnant, `Societe::add_commercial()` (qui ajoute sans
retirer et déclenche le trigger `COMPANY_LINK_SALE_REPRESENTATIVE`). Les tiers supprimés
sont ignorés et signalés. Tout ou rien.

## Vérification d'un fichier

`SalonerpVoteFile::analyse($json, $privateKey = '', $expected = null, $official = null,
$receiptJson = '')` ne touche pas à la base : lecture stricte (format, types,
hexadécimal, base64, 5 Mo au plus), campagne attendue (même `genesis_hash`), intégrité de
la genèse et de la chaîne, taille des bulletins (un contrôle à part entière, **sans
clé** : la genèse doit porter un `ballot_size` valide, et chaque chiffré doit faire
exactement `ballot_size + 48` octets ; sinon le fichier est signalé non conforme, et une
genèse sans `ballot_size` n'est jamais déchiffrée, quelle que soit la clé fournie),
comparaison **vote par vote, en entier** avec la chaîne officielle (un fichier plus
ancien en est le début ; sinon, rang de la première divergence), puis déchiffrement si
une clé valide est fournie. Chaque vote ouvert est soumis au même contrôle de taille que
`reveal()` (`ballot_size` exact, `sodium_unpad()` réussi) ; un vote qui y échoue est
signalé, sans faire échouer les autres. L'onglet Déchiffrer lui passe la campagne et sa
chaîne ; le déchiffrement externe, rien d'autre que le fichier et la clé.

**Vérification d'un reçu** (`$receiptJson`, facultatif) : lecture stricte du reçu à part
(format, types, base64, 1 Mo au plus), même `genesis_hash` que le fichier, `seq` présent
dans le fichier, le `ballot` décodé se désigne bien lui-même par cette campagne et ce
`seq`, `strlen(ballot) < ballot_size`, puis recalcul
`Campaign::sealBallot(sodium_pad(ballot, ballot_size), clé publique de la genèse,
ephemeral_key)` et comparaison `hash_equals` avec le chiffré du vote de rang `seq` du
fichier. **Aucune clé de campagne n'est jamais nécessaire** : cette vérification
fonctionne pendant le vote, bien avant toute révélation — c'est le seul moyen de
contester un vote alors que la campagne est encore ouverte. Verdict rendu sans accuser
personne : « correspond » (avec le vote affiché en clair, noms des tiers) ou « ne
correspond pas » (le reçu ne prouve rien, ce qui n'incrimine ni le reçu ni le fichier pris
isolément). Utilisée par le déchiffrement externe (le litige se vérifie sur une autre
instance, jamais sur celle qui a servi à voter) et par `dechiffrer-votes.php FICHIER.json
[CLÉ_PRIVÉE] [--recu RECU.json]`.

**Reçu seul, sans fichier fourni.** `Campaign::checkReceiptAgainstOfficialChain($receiptJson)`
construit elle-même le fichier de référence (`buildVoteFile()`) et lui applique `analyse()`
avec la campagne et sa chaîne officielle : c'est ce qui permet à l'onglet Déchiffrer
d'accepter un reçu sans qu'aucun fichier des votes n'ait été déposé. Le verdict n'y a de
sens que pour cette instance — voir « Contester un vote » sur ce que cela ne prouve pas.
`salonerpPrintReceiptCheck($receipt, $againstOfficial = false)` rend le rapport de
vérification d'un reçu ; `$againstOfficial` (vrai sur ce chemin, faux partout ailleurs)
change uniquement l'avertissement affiché à côté d'un verdict « correspond » : l'empreinte
du fichier à comparer, ou le renvoi vers le Déchiffrement externe d'une autre instance.

## Sécurité

- Toute action qui modifie quelque chose est **POST uniquement**, avec contrôle explicite
  du token CSRF (`salonerpCheckPostToken()`), indépendamment de
  `MAIN_SECURITY_CSRF_WITH_TOKEN`. Les confirmations passent par un formulaire POST, pas
  par la boîte ajax (qui répond en GET).
- Chaque page vérifie le module, l'utilisateur interne, le droit de lecture
  (`restrictedArea`) et l'entité de la campagne.
- Entrées via `GETPOST` typé ; sorties échappées ; aucun fichier déposé (fichier des
  votes ou reçu) n'est écrit sur le disque (`is_uploaded_file`, lecture en mémoire).
- La page de la clé privée, et celle du reçu de vote, sont servies avec
  `Cache-Control: no-store`.
- La clé éphémère (`eph_sk`) d'un vote n'est **jamais stockée côté serveur** : ni base, ni
  session, ni journal, ni fichier. Elle n'existe que dans les variables locales de
  `castVote()`, le temps de construire le reçu, puis est effacée (`sodium_memzero`).

## Triggers

`SALONERP_CAMPAIGN_VALIDATE` à la validation. `COMPANY_LINK_SALE_REPRESENTATIVE`
(standard Dolibarr) à chaque commercial ajouté par l'attribution.

## Limites connues

- Une seule règle d'égalité (`B_E`) existe ; la colonne `tie_rule` permettra d'en ajouter.
- La liste des campagnes n'applique pas les transitions liées à l'horloge : une campagne
  dont la date de fin est passée y garde son ancien statut jusqu'à ce qu'on ouvre sa fiche.
- Sur un écran de téléphone, les pages débordent légèrement en largeur, comme les listes
  standard de Dolibarr.

## Licence

Ce module est distribué sous licence **GPLv3** ou, à votre choix, toute version
ultérieure. Voir le fichier `LICENSE`. Les fichiers dérivés du cœur de Dolibarr
reprennent les mentions de copyright de leur original.
