# Se repérer dans RIVO

RIVO est le logiciel de gestion de la Clinique Saint Georges. Chaque site (Mampikony, Ambondromamy, Boriziny) a son application et sa propre base ; le portail Super Administration (admin.rivo.mg) règle les sites à distance.

## Menu, vue d'ensemble et recherche

- Le **menu latéral** (à gauche) ne montre que les modules auxquels votre compte a droit. Certains sont regroupés sous une entrée mère (Médecine, Réception, Soins, Pharmacie, Ressources humaines, Référentiels) qui se déplie.
- L'ordre des entrées se règle avec « Personnaliser l'ordre » ; il reste propre à votre compte.
- La **Vue d'ensemble** (page d'accueil) montre les tuiles de vos modules et ce qui attend votre travail.
- La **recherche en haut de l'écran** retrouve un patient ou un passage par son nom ou son numéro (au moins 2 caractères). Elle demande le droit de voir les patients.
- Sur téléphone, le menu s'ouvre avec le bouton en haut à gauche.

## Pourquoi un bouton est grisé, verrouillé ou une page refusée

Les actions dépendent des **droits** (permissions) de votre compte, jamais du seul nom de votre rôle.

- Une page refusée affiche le **droit qui manque** (par exemple `consultations.update`) et où il s'accorde. Seul un administrateur (Super Administrateur, ou un compte autorisé) peut l'accorder, dans « Rôles & permissions ».
- Un droit peut venir du **socle de votre rôle** ou d'une **exception individuelle**. Un refus individuel l'emporte toujours sur le socle du rôle : même si le rôle a le droit, un compte refusé ne l'a pas. L'administrateur lève ce refus en remettant le droit sur « Selon le rôle ».
- Un bouton **verrouillé** (cadenas) ou grisé dit en général pourquoi au survol ou juste en dessous : étape précédente à terminer, patient pris en charge par un collègue, dossier clos, droit manquant.
- Certaines actions dépendent de l'**état du dossier** et pas des droits : une consultation clôturée est en lecture seule, un passage clos par la sortie administrative ne se modifie plus.
- Pendant une **maintenance** du site, seuls les comptes autorisés peuvent travailler ; un bandeau prévient 24 heures avant.

## Mon profil, mot de passe et apparence

- Menu du compte (en haut à droite) › **Mon profil** : vos informations, votre rôle, vos droits effectifs.
- La partie « Mot de passe » de Mon profil change votre mot de passe : l'ancien est demandé, et les autres sessions de votre compte sont fermées.
- Apparence : **Clair / Système / Sombre** (en haut de l'écran), et dans Mon profil la taille du texte, les animations et le contraste.
- Le nom, l'email, le rôle et les droits ne se modifient pas soi-même : ils relèvent de l'administration.

## Notifications et points à traiter

La **cloche** en haut de l'écran a deux onglets : « Notifications » (les dernières, à marquer lues ou archiver) et « À traiter » (le travail en attente). La page `/notifications` garde toutes les notifications, avec filtres Non lues / Archivées.

## Messagerie professionnelle

Le menu **Messagerie** ouvre la boîte email professionnelle reliée à votre fiche employé (droit `webmail.view`). Après une connexion normale à RIVO, la boîte s'ouvre sans retaper le mot de passe s'il est le même. Si le mot de passe de la boîte est différent, il est demandé une fois.

## Corbeille

Les éléments supprimés d'un site (patients, prestations, adresses, organismes, caisses…) vont dans la **Corbeille** (droit `trash.view`). Une restauration demande en plus le droit de restauration de la catégorie. Les données médicales et financières critiques ne se suppriment pas : elles s'annulent ou se corrigent.

## Assistant GasyCoder AI

La bulle au petit robot, en bas à droite de chaque page, ouvre **GasyCoder AI**. On la glisse où l'on veut ; sa fenêtre s'agrandit ou passe en plein écran, et se réduit à nouveau en bulle (Échap). On y pose une question sur le logiciel, en français, en malgache ou en anglais ; les réponses arrivent au fil de l'eau, avec des questions proposées selon la page d'où l'on vient et le métier du compte, puis des questions de suivi. **Nouvelle conversation** repart de zéro ; l'**historique** garde vos conversations, que vous seul voyez et pouvez supprimer. L'assistant n'apparaît qu'une fois activé et configuré par l'administrateur.

## Confidentialité avec l'assistant

L'assistant aide à utiliser le logiciel. Il n'a pas besoin du nom, du téléphone ni des informations médicales d'un patient : posez la question de façon générale (« le patient ouvert », « ce passage »). Il ne fait aucune action à votre place et ne remplace jamais une décision médicale.
