<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>

<body style="margin:0; padding:0; background-color:#EBF5FF; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EBF5FF; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px; background:#F9FAFB; border-radius:16px; overflow:hidden; border:1px solid #E0ECFF;">
                @yield('content')

                <tr>
                    <td style="padding:16px 24px; background:#F9FAFB; border-top:1px solid #E5E7EB;">
                        <p style="margin:0; font-size:11px; color:#6B7280;">
                            You’re receiving this because someone invited you to collaborate on a project at MonkeysRaiser.
                        </p>
                        <p style="margin:4px 0 0; font-size:11px; color:#9CA3AF;">
                            © {{ date('Y') }} MonkeysRaiser. All rights reserved.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

</body>
</html>
