# a_priori_algo_php5
Implementation of A Priori algo in PHP5 (ZF1 version)
https://fr.wikipedia.org/wiki/Algorithme_APriori

A partir d'une liste de produits évalués par un grand nombre d'utilisateurs, on cherche des couples de produits (si une personne aime le produit A, elle aimera aussi le produit B à détecter).

Paramètres importants dans le script : 
$iNbReviewsMin // minimum d'avis pour les produits pris en compte
$iNbReviewsMin_2ItemsSets // minimum d'avis en commun pour qu'une paire de produits soit prise en compte
$iNbOwnersMin_2ItemsSets // minimum de owners en commun pour qu'une paire de produits soit prise en compte
$iConfidenceMinimum // minimum de % de membres aimant / ayant prod2 si prod1 pour que la paire soit prise en compte
