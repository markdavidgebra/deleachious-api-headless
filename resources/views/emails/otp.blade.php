<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light only">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $headline }}</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
  <style>
    :root { color-scheme: light only; }
    @media (max-width: 620px) {
      .wrap { width: 100% !important; }
      .pad { padding: 36px 24px 32px !important; }
      .code { font-size: 28px !important; letter-spacing: 8px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#24140f;-webkit-text-size-adjust:100%;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
    {{ $preheader }}
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#24140f;margin:0;padding:0;">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" class="wrap" style="width:560px;max-width:560px;background-color:#402218;">
          <tr>
            <td class="pad" style="padding:48px 44px 40px;text-align:center;">
              <img
                src="{{ $message->embed(resource_path('images/mail/mark-cream.png')) }}"
                width="72"
                height="72"
                alt="Daleachious"
                style="display:block;margin:0 auto 18px;border:0;outline:none;width:72px;height:72px;"
              >
              <p style="margin:0 0 6px;font-family:Georgia,'Times New Roman',Times,serif;font-size:32px;line-height:1.15;letter-spacing:0.04em;color:#f6e5b3;">
                Daleachious
              </p>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:14px auto 12px;">
                <tr>
                  <td style="width:48px;height:1px;background-color:#f6e5b3;font-size:1px;line-height:1px;">&nbsp;</td>
                </tr>
              </table>
              <p style="margin:0 0 36px;font-family:Georgia,'Times New Roman',Times,serif;font-size:16px;font-style:italic;line-height:1.4;color:#f6e5b3;">
                The house is open.
              </p>

              <p style="margin:0 0 8px;font-family:Georgia,'Times New Roman',Times,serif;font-size:11px;letter-spacing:0.28em;text-transform:uppercase;color:#d9c48a;">
                {{ $eyebrow }}
              </p>
              <p style="margin:0 0 12px;font-family:Georgia,'Times New Roman',Times,serif;font-size:26px;line-height:1.25;color:#f6e5b3;">
                {{ $headline }}
              </p>
              <p style="margin:0 0 28px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#e8d7a8;">
                {{ $lede }}
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px;">
                <tr>
                  <td align="center" style="border:1px solid #f6e5b3;padding:22px 16px;">
                    <p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:0.22em;text-transform:uppercase;color:#d9c48a;">
                      One-time password
                    </p>
                    <p class="code" style="margin:0;font-family:Georgia,'Times New Roman',Times,serif;font-size:36px;line-height:1.2;letter-spacing:10px;color:#f6e5b3;">
                      {{ $code }}
                    </p>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 36px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;color:#cbb98a;">
                This code expires in 15 minutes.
              </p>
              <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55;color:#a8946c;">
                If you did not ask for this, you can ignore this email.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
