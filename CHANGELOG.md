# Änderungen

Jede Version beginnt mit einer Zeile `## X.Y.Z (JJJJ-MM-TT)`, die oberste ist die aktuelle.
Die Berufsmesse liest diese Datei für die Versionsnummer in der Seitenleiste, den
Änderungsverlauf im Versionsdialog und die Update-Prüfung (siehe README, Abschnitt
„Versionen und Updates“). Einträge kurz und für Admins verständlich halten.

## 1.6.0 (2026-11-11)

- Schnittstelle für die Android-App: Anmeldung, alle Funktionen passend zu Rolle und Rechten, Tagesplan, Anmeldungen, Check-in, Scanner, Orga-Chat und Benachrichtigungen.
- Seiten, die die App öffnet, erscheinen dort ohne eigene Seitenleiste und Kopfzeile und im Hell- oder Dunkelmodus der App.
- Sicherheit: Jede Passwortänderung (selbst oder durch Admins, auch über das Zugangsdaten-PDF) meldet das Konto auf allen Geräten der App ab.

## 1.5.0 (2026-11-06)

- Versionsnummer unten in der Seitenleiste, für alle sichtbar. Admins öffnen per Klick diesen Änderungsverlauf.
- Update-Prüfung für Global-Admins: bei jeder Anmeldung und einmal täglich, dazu der Knopf „Nach neuen Versionen suchen“ im Versionsdialog. Eine neue Version meldet die Berufsmesse einmalig als Benachrichtigung.

## 1.4.0 (2026-10-23)

- Sicherheitscheck der Server-Umgebung (PHP, Systempakete, MariaDB) alle 6 Stunden; neue hohe oder kritische Lücken gehen als Benachrichtigung an die Global-Admins.
- Schutz gegen PHP2Shell: Das Basis-Image wird bei jedem Build frisch geholt, PHP muss mindestens 8.3.36 sein.

## 1.3.0 (2026-10-14)

- Anmeldung per Einmalcode an die hinterlegte E-Mail-Adresse, deckt auch „Passwort vergessen“ ab. Global-Admin: Test-Mail und Versandprotokoll.
- Aussteller-Absagen laufen zentral: Schülerbuchungen ruhen statt gelöscht zu werden und kommen bei einer Rücknahme zurück.
- Einladungen an Aussteller nur noch per Link, der einmal gilt; Branchen gehören je einer Schule.
- Ankündigungen erscheinen auf der Startseite jeder Rolle; kleinere Korrekturen aus der Handbuch-Erstellung.

## 1.2.0 (2026-09-28)

- Orga-Tool: Teams mit Leitungen, Ziele, Organisationspunkte mit Checklisten, Team-Chats, Gesamtchat, Leitungsrunde, Protokolle und Countdown bis zum Abschlusstermin.

## 1.1.0 (2026-09-08)

- Neues Design mit warmer Farbpalette, Live-Suche in allen Filterformularen, Passwort-anzeigen-Knopf beim Login.
- Rechte enden mit der Rolle: Wer eine andere Rolle bekommt, verliert die alten Einzelrechte.
- Korrekturen an Zuteilung, Offline-Scans und Rate-Limits; Raumzuteilung per Ziehen.

## 1.0.0 (2026-08-31)

- Neubau der Berufsmesse: mehrere Schulen, granulares Rechtesystem, Aussteller-Portal, Feedback-Bögen und Darstellung pro Schule.
- Aussteller-Pipeline mit Jahreshistorie, Editionen klonen, Zeitraster-Assistent, Zuteilungs-Probelauf, Warteliste, Raumplanung, Leitstand, Ausfall-Management, Offline-Scanner und Jahresvergleich.
