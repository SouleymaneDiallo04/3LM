# ADR 0003 — Modèle de données : companies (SIREN) + establishments (SIRET)

- **Date** : 2026-07-09
- **Statut** : accepté

## Contexte

Le CDC (§6.1) modélise une table `companies` unique autour du `bce_number`
belge. Le registre français SIRENE distingue structurellement :

- l'**unité légale** (SIREN, 9 chiffres) : dénomination, forme juridique,
  statut, catégorie d'activité principale ;
- l'**établissement** (SIRET, 14 chiffres = SIREN + NIC) : adresse physique,
  activité locale, enseigne — c'est lui que l'on prospecte et géolocalise.

Le correctif 5 du CDC impose déjà que « la recherche géographique s'appuie en
priorité sur les unités d'établissement ». La volumétrie France (~15 M
d'établissements actifs pour ~11 M d'unités légales) rend la distinction
indispensable.

## Décision

Deux tables : `companies` (une ligne par SIREN) et `establishments` (une ligne
par SIRET, FK vers companies, colonne `location` PostGIS). Les clés de
déduplication fortes (EF-04.1) sont les contraintes UNIQUE sur `siren` et
`siret`. La « fiche entreprise » du CDC (EF-02) est rendue au niveau
établissement, enrichie des données de l'unité légale.

Les données d'enrichissement (téléphone, site, email, réseaux sociaux,
horaires, note/avis, réputation, scores) sont portées par l'établissement :
elles décrivent un lieu d'activité, pas une personne morale.

## Conséquences

- L'import SIRENE alimente les deux tables (fichiers stock UniteLegale et
  StockEtablissement de l'INSEE).
- Le statut de diffusion SIRENE est respecté dès l'import : les unités
  non-diffusibles sont exclues (conformité CNIL, note de cadrage §3).
- La colonne `embedding` (pgvector, EF-08.3) sera ajoutée par migration en
  phase 5 — l'image PostgreSQL actuelle (postgis/postgis) n'embarque pas
  pgvector ; l'extension sera ajoutée à l'image à ce moment-là.
