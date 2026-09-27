{{-- Welcome email of a new account (AccountInvitationNotification).
     Inline styles only: mail clients strip <style> blocks and ignore classes. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Bienvenue sur {{ $clinic }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<span style="display:none;max-height:0;overflow:hidden;">Votre compte {{ $clinic }} est prêt : choisissez votre mot de passe pour l’activer.</span>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;">
                {{-- En-tête de marque --}}
                <tr>
                    <td style="background-color:#1f5f8b;border-radius:14px 14px 0 0;padding:26px 32px;">
                        <p style="margin:0;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#bfdbfe;font-weight:700;">{{ $site }}</p>
                        <p style="margin:6px 0 0;font-size:22px;font-weight:700;color:#ffffff;">{{ $clinic }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#ffffff;padding:32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
                        <h1 style="margin:0;font-size:22px;line-height:30px;color:#0f172a;">Bienvenue, {{ $name }}</h1>
                        <p style="margin:12px 0 0;font-size:15px;line-height:24px;">
                            Un compte vient d’être créé pour vous sur l’application de gestion de la clinique.
                            Pour des raisons de sécurité, <strong>vous devez choisir votre propre mot de passe</strong> avant votre première connexion :
                            personne d’autre ne le connaît, pas même l’administrateur.
                        </p>

                        {{-- Récapitulatif du compte --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0 0;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                            <tr>
                                <td style="padding:14px 18px;font-size:13px;line-height:22px;">
                                    <span style="color:#64748b;">Identifiant de connexion</span><br>
                                    <strong style="color:#0f172a;font-size:14px;">{{ $email }}</strong>
                                </td>
                            </tr>
                            @if ($role)
                                <tr>
                                    <td style="padding:0 18px 14px;font-size:13px;line-height:22px;">
                                        <span style="color:#64748b;">Rôle</span><br>
                                        <strong style="color:#0f172a;font-size:14px;">{{ $role }}@if ($profile) · {{ $profile }}@endif</strong>
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:0 18px 14px;font-size:13px;line-height:22px;">
                                    <span style="color:#64748b;">Établissement</span><br>
                                    <strong style="color:#0f172a;font-size:14px;">{{ $site }}</strong>
                                </td>
                            </tr>
                        </table>

                        {{-- Action --}}
                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px auto 0;">
                            <tr>
                                <td align="center" style="border-radius:10px;background-color:#1f5f8b;">
                                    <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">Définir mon mot de passe</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:12px 0 0;text-align:center;font-size:13px;color:#64748b;">
                            Ce lien est personnel et reste valable <strong>{{ $expiresInHours }} heures</strong>.
                        </p>

                        {{-- Sécurité --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:28px 0 0;background-color:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
                            <tr>
                                <td style="padding:16px 18px;font-size:13px;line-height:21px;color:#78350f;">
                                    <strong style="display:block;margin-bottom:6px;color:#92400e;">Pour protéger les données des patients</strong>
                                    • Au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.<br>
                                    • Un mot de passe que vous n’utilisez nulle part ailleurs.<br>
                                    • Ne le communiquez jamais, ni par téléphone ni par message : la clinique ne vous le demandera jamais.<br>
                                    • Vous n’avez pas demandé ce compte ? Ne cliquez pas et prévenez l’administration de la clinique.
                                </td>
                            </tr>
                        </table>

                        <p style="margin:24px 0 0;font-size:12px;line-height:19px;color:#94a3b8;">
                            Le bouton ne fonctionne pas ? Copiez ce lien dans votre navigateur :<br>
                            <a href="{{ $url }}" style="color:#1f5f8b;word-break:break-all;">{{ $url }}</a>
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#ffffff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 14px 14px;padding:18px 32px;font-size:12px;line-height:18px;color:#94a3b8;">
                        Une fois votre mot de passe choisi, connectez-vous sur <a href="{{ $loginUrl }}" style="color:#1f5f8b;">{{ $loginUrl }}</a>.<br>
                        Message automatique de {{ $clinic }} — merci de ne pas y répondre.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
