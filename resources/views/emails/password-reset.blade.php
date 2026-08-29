<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5; padding: 20px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0"
                style="background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                <!-- Encabezado -->
                <tr>
                    <td style="background-color:#0c4a6e; color:#ffffff; padding:20px; text-align:center;">
                        <h2 style="margin:0;">🔒 Recuperar contraseña</h2>
                        <p style="margin:0; font-size:14px;">FACEC</p>
                    </td>
                </tr>

                <!-- Cuerpo del mensaje -->
                <tr>
                    <td style="padding: 30px;">
                        <p>Hola {{ $user->name ?? $user->user }},</p>
                        <p>
                            Recibimos una solicitud para restablecer la contraseña de tu cuenta ({{ $user->user }}).
                            Haz clic en el siguiente botón para establecer una nueva contraseña.
                        </p>

                        <p style="text-align:center; margin: 30px 0;">
                            <a href="{{ $resetUrl }}"
                                style="background-color:#0c4a6e; color:#ffffff; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold;">
                                Restablecer contraseña
                            </a>
                        </p>

                        <p style="font-size: 14px; color: #555;">
                            Este enlace es válido por 60 minutos. Si no solicitaste este cambio, puedes ignorar este
                            correo — tu contraseña no será modificada.
                        </p>

                        <p style="font-size: 14px; color: #555;">
                            Si tienes dudas o necesitas ayuda, puedes contactarnos por WhatsApp.
                        </p>
                    </td>
                </tr>

                <!-- Pie de página -->
                <tr>
                    <td style="background-color:#f1f5f9; padding:20px; font-size:12px; color:#555; text-align:center;">
                        FACEC | Firma y Facturación Electrónica
                        <br />
                        🌐 Visítanos en <a href="https://facec.ec" style="color:#555;">facec.ec</a>
                        &nbsp;&nbsp;|&nbsp;&nbsp; 📲 WhatsApp: <a href="https://wa.me/593959649714"
                            style="color:#555;">0959649714</a>

                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
