{{-- ADR-202 — « Votre compte est validé » (AccountActivatedMail), à la première connexion.
     Styles en ligne seulement : les messageries retirent les blocs <style>. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Votre compte {{ $clinic }} est validé</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<span style="display:none;max-height:0;overflow:hidden;">Votre compte {{ $clinic }} est activé : vous vous connectez désormais avec le mot de passe que vous venez de choisir.</span>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;">
                <tr>
                    <td style="background-color:#1f5f8b;border-radius:14px 14px 0 0;padding:26px 32px;">
                        <p style="margin:0;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#bfdbfe;font-weight:700;">{{ $site }}</p>
                        <p style="margin:6px 0 0;font-size:22px;font-weight:700;color:#ffffff;">{{ $clinic }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#ffffff;padding:32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
                        <p style="margin:0;display:inline-block;padding:4px 12px;border-radius:999px;background-color:#ecfdf5;color:#047857;font-size:12px;font-weight:700;">Compte validé</p>
                        <h1 style="margin:14px 0 0;font-size:22px;line-height:30px;color:#0f172a;">Bienvenue, {{ $name }}</h1>
                        <p style="margin:12px 0 0;font-size:15px;line-height:24px;">
                            Votre compte a été validé le <strong>{{ $activatedAt }}</strong>, avec le mot de passe que vous venez de choisir.
                            Personne d’autre ne le connaît : c’est lui qui ouvre désormais votre compte, et votre messagerie professionnelle.
                        </p>

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

                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px auto 0;">
                            <tr>
                                <td align="center" style="border-radius:10px;background-color:#1f5f8b;">
                                    <a href="{{ $loginUrl }}" target="_blank" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">Se connecter</a>
                                </td>
                            </tr>
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:28px 0 0;background-color:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
                            <tr>
                                <td style="padding:16px 18px;font-size:13px;line-height:21px;color:#78350f;">
                                    <strong style="display:block;margin-bottom:6px;color:#92400e;">Ce n’était pas vous ?</strong>
                                    Si vous n’avez pas activé ce compte vous-même, prévenez tout de suite le RH ou l’administration de la clinique :
                                    quelqu’un d’autre a pu choisir votre mot de passe.<br>
                                    Ne communiquez jamais votre mot de passe : la clinique ne vous le demandera jamais.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#ffffff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 14px 14px;padding:18px 32px;font-size:12px;line-height:18px;color:#94a3b8;">
                        Changer votre mot de passe : <a href="{{ $profileUrl }}" style="color:#1f5f8b;">{{ $profileUrl }}</a>.<br>
                        Message automatique de {{ $clinic }} — merci de ne pas y répondre.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
