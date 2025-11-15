@extends('layouts.email')

@section('title')
Welcome to MonkeysRaiser
@endsection

@section('content')

<tr>
    <td align="center" style="padding:24px; background:linear-gradient(135deg,#0066CC,#003D7A);">
        <img src="https://monkeysraiser.com/logo.svg"
             alt="MonkeysRaiser"
             width="140"
             style="display:block; margin-bottom:16px;">
        <div style="color:#E5F0FF; font-size:11px; text-transform:uppercase; letter-spacing:0.14em; font-weight:600;">
            Welcome
        </div>
        <div style="color:#FFF; font-size:22px; font-weight:800; margin-top:6px;">
            Great to have you on board
        </div>
    </td>
</tr>

<tr>
    <td style="background:#FFFFFF; padding:24px;">

        <?php if (!empty($fullName)) { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827;">
                Hi <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>,
            </p>
        <?php } else { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827;">
                Hi there,
            </p>
        <?php } ?>

        <?php if (isset($role) && $role === 'founder') { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827; line-height:1.5;">
                Welcome to <strong>MonkeysRaiser</strong> 🎉
                You’re set up as a <strong>founder</strong>. Here you can showcase your startup,
                share traction, and connect with investors in a clear, structured way.
            </p>
        <?php } elseif (isset($role) && $role === 'investor') { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827; line-height:1.5;">
                Welcome to <strong>MonkeysRaiser</strong> 🎉
                You’re set up as an <strong>investor</strong>. You’ll get curated access to startup
                profiles with clear context so you can qualify opportunities fast.
            </p>
        <?php } else { ?>
            <p style="margin:0 0 14px; font-size:14px; color:#111827; line-height:1.5;">
                Welcome to <strong>MonkeysRaiser</strong> 🎉
                You now have access to our platform to connect founders and investors.
            </p>
        <?php } ?>

        <p style="margin:0 0 16px; font-size:13px; color:#4B5563; line-height:1.6;">
            Next step: complete your profile so others understand who you are and how you work.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0 20px;">
            <tr>
                <td>
                    <a href="<?= htmlspecialchars($primaryUrl ?? '#', ENT_QUOTES, 'UTF-8') ?>"
                       style="display:inline-block; padding:10px 20px; color:#FFFFFF; background:#0066CC; text-decoration:none; font-size:14px; font-weight:600; border-radius:999px;">
                        Complete your profile
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:0 0 10px; font-size:12px; color:#6B7280; line-height:1.6;">
            You can always update your profile and projects from your dashboard.
        </p>
    </td>
</tr>

@endsection
