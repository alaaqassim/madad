{{--
    The credentials email.

    Table layout and inline styles throughout: mail clients strip <style>
    blocks, ignore flexbox and grid, and several still lay out with tables
    only. This is not how the portal is built and should not be made to match
    it - it is how an email survives Outlook, Gmail and a phone.

    dir="rtl" is set on the html element and on every table, because a client
    that drops one usually honours the other.
--}}
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $competitionName }}</title>
</head>
<body style="margin:0; padding:0; background:#f8f3ea; font-family:'Segoe UI', Tahoma, Arial, sans-serif; color:#17322c;">

<table dir="rtl" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8f3ea; padding:24px 12px;">
    <tr>
        <td align="center">

            <table dir="rtl" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px; background:#ffffff; border:1px solid #e0d8c9; border-radius:14px; overflow:hidden;">

                {{-- Brand band --}}
                <tr>
                    <td style="background:#14544a; padding:22px 28px;">
                        <div style="color:#ffffff; font-size:20px; font-weight:700;">{{ $competitionName }}</div>
                        <div style="color:rgba(255,255,255,0.82); font-size:13px; padding-top:4px;">بيانات الدخول الخاصّة بك</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px;">

                        <p style="margin:0 0 16px; font-size:16px;">مرحبًا {{ $name }}،</p>

                        <p style="margin:0 0 22px; font-size:15px; line-height:1.9; color:#17322c;">
                            تمّ تسجيلك في {{ $competitionName }}. هذه بيانات دخولك، وهي خاصّة بك وحدك.
                        </p>

                        {{-- Credentials --}}
                        <table dir="rtl" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="background:#f1e9db; border:1px solid #cfc4af; border-radius:10px; margin:0 0 22px;">
                            <tr>
                                <td style="padding:18px 20px;">
                                    <div style="font-size:12px; color:#8f6f26; font-weight:700; padding-bottom:4px;">البريد الإلكترونيّ</div>
                                    <div style="font-size:15px; direction:ltr; text-align:right; font-family:Consolas,'Courier New',monospace; padding-bottom:14px;">{{ $email }}</div>

                                    <div style="font-size:12px; color:#8f6f26; font-weight:700; padding-bottom:4px;">كلمة المرور</div>
                                    <div style="font-size:17px; direction:ltr; text-align:right; font-family:Consolas,'Courier New',monospace; letter-spacing:1px; font-weight:700;">{{ $password }}</div>
                                </td>
                            </tr>
                        </table>

                        @if ($startsAt)
                            <p style="margin:0 0 22px; font-size:15px; line-height:1.9;">
                                تبدأ المسابقة في
                                <strong>{{ $startsAt->format('Y/m/d') }}</strong>
                                الساعة
                                <strong>{{ $startsAt->format('H:i') }}</strong>@if ($endsAt)، وتُغلق البوّابة الساعة <strong>{{ $endsAt->format('H:i') }}</strong>@endif.
                            </p>
                        @endif

                        {{-- Call to action. A table so the whole button is clickable in Outlook. --}}
                        <table dir="rtl" role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="background:#c9a24d; border-radius:10px;">
                                    <a href="{{ $portal }}"
                                       style="display:inline-block; padding:13px 30px; font-size:15px; font-weight:700; color:#3c2f0d; text-decoration:none;">
                                        الدخول إلى المسابقة
                                    </a>
                                </td>
                            </tr>
                        </table>

                        @if ($questionCount && $secondsPerQuestion)
                            <table dir="rtl" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="border-top:1px solid #e0d8c9; padding-top:6px;">
                                <tr>
                                    <td style="padding-top:18px; font-size:14px; line-height:2; color:#17322c;">
                                        <strong style="color:#0f4a40;">قبل أن تبدأ</strong><br>
                                        • عدد الأسئلة: {{ $questionCount }} سؤالًا.<br>
                                        • لكلّ سؤال {{ $secondsPerQuestion }} ثانية، وبمجرّد إجابتك ينتقل النظام إلى السؤال التالي فورًا.<br>
                                        @if ($durationMinutes)
                                            • مدّة الامتحان {{ $durationMinutes }} دقيقة تبدأ من لحظة ضغطك «ابدأ».<br>
                                        @endif
                                        • لا يمكن إيقاف الوقت ولا العودة إلى سؤال سابق، وانقطاع الاتّصال لا يوقف المؤقّت.<br>
                                        • ادخل من مكان اتّصاله مستقرّ، وابدأ حين تكون مستعدًّا.
                                    </td>
                                </tr>
                            </table>
                        @endif

                    </td>
                </tr>

                <tr>
                    <td style="background:#f1f6f0; padding:16px 28px; font-size:12px; line-height:1.8; color:#7c8b85;">
                        هذه الرسالة موجَّهة إليك وحدك، ولا تشاركها مع أحد.<br>
                        إن وصلتك بالخطأ فتجاهلها.
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
