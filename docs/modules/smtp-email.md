# smtp-email (WP: SmtpEmail)

Posílá veškerou poštu Joomly přes SMTP server nebo Brevo API, s volitelným záložním spojením a přepsáním From.

## Jak zachytává poštu
Joomla nemá událost "mail odeslán" a všechny cesty (`Factory::getMailer()`, `Mail::getInstance()`, `MailTemplate`, pluginy,
reset hesla) berou mailer ze služby `MailerFactoryInterface` v DI kontejneru. Sdílená vrstva `Modules/SmtpEmail/Mail/`:
- `MailHub::install()` vymění chráněnou službu za `UxMailerFactory` (přímý zásah do `Container::$resources` přes `Closure::bind`,
  protože `extend()` na chráněný klíč vyhodí `ProtectedKeyException`),
- továrna vrací `UxMail extends Joomla\CMS\Mail\Mail` (stav vnitřního maileru se zkopíruje reflexí),
- `UxMail::Send()` zavolá transport (smtp-email), zapíše do pozorovatelů (email-log) a zachová chování výjimek Joomly.
Moduly smtp-email, email-log, email-health lze zapínat nezávisle; kdo nabootuje první, vrstvu nainstaluje.

## Nastavení
Spojení (SMTP / Brevo / Joomla výchozí), záložní spojení, From e-mail/jméno + vynucení, SMTP host/port/šifrování/uživatel/heslo (password),
Brevo API klíč (password). Nenastavené hlavní spojení = Joomla posílá podle globální konfigurace.

## API
`GET status`, `POST test {to?, mailer?}` (mailer = jedno spojení bez fallbacku), `POST brevo/verify`. Modul vyžaduje `core.admin`.

## Rozdíly proti WP verzi
- Jen SMTP a Brevo (chybí Gmail OAuth, SendGrid, Mailgun, Postmark, SES). Jádro je rozšiřitelné přes `MailTransport::attempt()`.
- Vlastní delivery log nemá; zapisuje email-log (sloupce `mailer`, `attempts` zachycují primární i záložní pokus).
- Brevo posílá strukturovaný JSON (příjemci, cc/bcc, reply-to, HTML+text, přílohy do 4 MB).
- Test "Joomla default only" využívá `mail()`/globální konfiguraci.

## Co chybí / omezení
- Test odesílání v globální konfiguraci Joomly (com_config) prochází také `UxMail` a ovlivní ho přepsání spojení.
- Mailer vytvořený před bootem modulů (před `onAfterInitialise`) zachycen není. Továrnu si ale uloží při konstrukci i systémové pluginy
  (J6: `plg_system_tasknotification`), proto `MailHub::install()` po výměně projde už nabootované pluginy (`ExtensionHelper::$extensions`)
  a těm, co implementují `MailerFactoryAwareInterface`, předá novou továrnu. Cizí kód, který si továrnu uložil jinam, zachycen není.
- Brevo API neumí vložené obrázky (`cid:`): zpráva s nimi spojení odmítne s jasnou chybou, takže se uplatní záložní spojení (SMTP). `messageId` z Brevo se ukládá do pokusu (`attempts[].message_id`).
- Odeslání přes Brevo API nebylo ověřeno platným klíčem (jen odmítnutý klíč, HTTP 401).
- Další poskytovatelé (Gmail OAuth, SendGrid, Mailgun, Postmark, SES) jsou další krok.
