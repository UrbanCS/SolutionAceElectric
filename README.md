# Facturation Ace Electric

Le dossier `facturation/` se déploie à côté de Joomla dans `public_html` par le gestionnaire de fichiers DirectAdmin ou cPanel. Le portail utilise PHP 8.1 ou ultérieur, cURL, les sessions PHP et HTTPS. Son interface est en anglais comme le site. En l’absence de configuration, la page publique dirige les employés vers QuickBooks Online. Le client accède à sa facture via le lien personnel envoyé par QuickBooks.

## Raccordement à QuickBooks Online Canada

1. Préparer une application Intuit et une société de test. Enregistrer exactement l’URL de rappel indiquée dans `config.example.php`. Utiliser les clés et le realm de la même société de test.
2. Dans le répertoire parent de `public_html`, créer `ace-billing` avec accès réservé au compte PHP (0700). Y copier le modèle sous le nom `config.php` (0600). Le remplir directement sur l’hébergement. Le processus PHP doit pouvoir créer le verrou et écrire les jetons dans ce dossier.
3. Renseigner le courriel Intuit vérifié de l’administrateur et les courriels autorisés en minuscules. Tous les employés de cette liste peuvent lire les clients et les tarifs ainsi que consulter, créer et envoyer des documents pour la société connectée. Retirer une adresse de la liste coupe l’accès aux requêtes suivantes.
4. Confirmer les codes de taxe de vente du catalogue, les devises et les prix avec la personne responsable. Configurer le code de repli uniquement s’il convient à tous les articles sans code. Activer la carte seulement si la société accepte effectivement les paiements en ligne.
5. L’administrateur ouvre `/facturation/?a=connect`, choisit la société correspondante et autorise l’application. Chaque employé utilise ensuite le bouton de connexion personnel.
6. En environnement de test, vérifier le refus d’un compte hors liste, la consultation d’une ancienne facture, le calcul des taxes et du solde, puis la création et l’envoi d’une facture et d’une estimation à une boîte de test. Vérifier les options de paiement présentées au destinataire et le rapprochement du solde dans QuickBooks.
7. Pour la production, utiliser une configuration privée et un répertoire de jetons ne contenant aucune connexion de test. Renseigner les clés de production, le realm approuvé, l’environnement `production` et renouveler le consentement de l’administrateur.

Les coordonnées bancaires et les cartes sont saisies chez Intuit. Aucun formulaire public ne recherche une facture par numéro ou courriel.
