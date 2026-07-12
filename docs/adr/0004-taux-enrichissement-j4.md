# ADR 0004 — Définition du taux d'enrichissement (critère J4)

Statut : proposé (à valider par 3LM)
Date : 2026-07-12

## Contexte

Le critère de passage du jalon **J4** du cahier des charges est :

> « ≥ 60 % des sites crawlés livrent un email ou un réseau social ; conformité robots. »

Sur les données réelles de la Gironde (1 200 fiches avec site web crawlées),
la mesure fait apparaître deux taux très différents selon le dénominateur :

| Base de calcul | Taux |
|---|---|
| Sur les sites **tentés** (1 200) | **57 %** |
| Sur les sites dont le **HTML a été récupéré** (804) | **85 %** |

L'écart tient à un fait de terrain : **~33 % des URL du champ `website`
de SIRENE sont mortes** (domaine expiré, site fermé, adresse obsolète). Ces
sites ne renvoient rien ; l'extraction n'y peut rien.

Autrement dit : **quand un site est joignable, on extrait un email ou un
réseau social dans 85 % des cas** — l'extraction n'est pas le facteur
limitant, la joignabilité des URL SIRENE l'est.

## Décision demandée à 3LM

Préciser ce que « sites crawlés » désigne dans le critère J4 :

- **Option A — sites effectivement récupérés (recommandé).** Un domaine mort
  n'a jamais été « crawlé ». Dénominateur = sites ayant renvoyé une réponse.
  Taux mesuré : **85 %**, critère largement atteint.
- **Option B — toutes les tentatives.** Dénominateur = toutes les fiches
  avec un `website`, y compris les domaines morts. Taux : **57 %**, sous la
  barre — non pour une raison d'extraction, mais parce que la base SIRENE
  contient des URL périmées hors de notre contrôle.

## Mesures d'atténuation déjà en place (indépendantes de la définition)

- **Nettoyage d'URL au crawl** : avant d'abandonner un site muet, on tente
  automatiquement `http→https` et la bascule `www↔apex` (un site sain répond
  du premier coup, sans requête superflue). Récupère une partie des faux
  négatifs.
- **Extraction renforcée** : liens `mailto:`, emails légèrement obfusqués,
  profils sociaux conservés malgré les paramètres de suivi, page Facebook
  reconnue comme réseau social.
- **Piste complémentaire (option produit)** : compter le **formulaire de
  contact** comme canal joignable — présent sur ~52 % des sites lus — ferait
  passer le taux « toutes tentatives » à ~67 %. Le §8 liste déjà le
  formulaire de contact comme donnée à extraire. À arbitrer par 3LM.

## Conséquences

- La recommandation est l'**option A**, honnête et défendable, complétée par
  le nettoyage d'URL (déjà livré) qui remonte aussi le taux de l'option B.
- Aucune de ces mesures ne franchit `robots.txt` ni ne collecte d'email
  nominatif par défaut (RGPD) — les deux plafonds de conformité restent tenus.
