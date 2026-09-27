Compte validé — bienvenue, {{ $name }}

Votre compte {{ $clinic }} ({{ $site }}) a été validé le {{ $activatedAt }}, avec le mot de passe que vous venez de choisir.
Personne d'autre ne le connaît : c'est lui qui ouvre votre compte et votre messagerie professionnelle.

Identifiant : {{ $email }}
@if ($role)
Rôle : {{ $role }}@if ($profile) · {{ $profile }}@endif

@endif
Établissement : {{ $site }}

Se connecter : {{ $loginUrl }}

Ce n'était pas vous ? Prévenez tout de suite le RH ou l'administration de la clinique.
Ne communiquez jamais votre mot de passe : la clinique ne vous le demandera jamais.

Changer votre mot de passe : {{ $profileUrl }}
Message automatique de {{ $clinic }}.
