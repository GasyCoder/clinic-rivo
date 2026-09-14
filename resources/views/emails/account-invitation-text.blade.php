Bienvenue, {{ $name }}

Un compte vient d'être créé pour vous sur l'application de {{ $clinic }} ({{ $site }}).
Pour des raisons de sécurité, vous devez choisir votre propre mot de passe avant votre première connexion.

Identifiant : {{ $email }}
@if ($role)
Rôle : {{ $role }}@if ($profile) · {{ $profile }}@endif

@endif
Établissement : {{ $site }}

Définir mon mot de passe (lien valable {{ $expiresInHours }} heures) :
{{ $url }}

Sécurité :
- Au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.
- Un mot de passe que vous n'utilisez nulle part ailleurs.
- Ne le communiquez jamais : la clinique ne vous le demandera jamais.
- Vous n'avez pas demandé ce compte ? Ne cliquez pas et prévenez l'administration.

Connexion : {{ $loginUrl }}
Message automatique de {{ $clinic }}.
