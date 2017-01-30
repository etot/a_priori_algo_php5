<?php
/**
 * Calculate the marks given to the products
*/

// No time limit !!!
set_time_limit(0);
#-------------------------------
#tempo memory limit hack
ini_set('memory_limit', '256M');
#-------------------------------

// chdir in CLI mode
chdir(dirname($argv[0]));

require('../application/bootstrap_cron.php');
$oConfig = Zend_Registry::get('oConfig');
$language_id = $oConfig->getLanguage();

$iNbOk = 0;

$fStartTime = microtime(true);
echo "start\n";

/*

CREATE TABLE `fr_reviewsPerUser` (
`user_id` MEDIUMINT( 10 ) NOT NULL ,
`sReviewsIds` TEXT NOT NULL ,
PRIMARY KEY ( `user_id` )
) ENGINE = MYISAM ;

CREATE TABLE `fr_productsPerUser` (
`user_id` MEDIUMINT( 10 ) NOT NULL ,
`sProductsIds` TEXT NOT NULL ,
PRIMARY KEY ( `user_id` )
) ENGINE = MYISAM ;

+ FULL TEXT INDEXs


CREATE TABLE IF NOT EXISTS `suggestedProducts` (
  `product_id` mediumint(9) NOT NULL,
  `suggestedProduct_id` mediumint(9) NOT NULL,
  `suggestedProduct_iScore` mediumint(9) NOT NULL,
  PRIMARY KEY (`product_id`,`suggestedProduct_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

*/

$oReviews = new Reviews();
$oSuggestedProducts = new SuggestedProducts();





/*******************************
 * 1 - FILL FR_REVIEWSPERUSER  *
 *******************************/

$oDb->exec('TRUNCATE TABLE fr_reviewsPerUser');

$oDb->exec('INSERT INTO fr_reviewsPerUser (user_id, sReviewsIds)
SELECT user_id, CONCAT(",", GROUP_CONCAT(DISTINCT product_id ORDER BY product_id), ",")
FROM fr_reviews
JOIN fr_reviewsProducts ON fr_reviewsProducts.review_id = fr_reviews.review_id
WHERE review_bDeleted = 0
AND review_iAverageMark > 5
GROUP BY user_id
');

echo "fr_reviewsPerUser insert ok \n";

$oQuery = $oDb->select()
               ->from('fr_reviewsPerUser',array(new Zend_Db_Expr('*')));
$aReviewsPerUsers = $oDb->fetchPairs($oQuery);

$aUserIdsToDelete = array();
foreach($aReviewsPerUsers as $user_id => $sReviewsIds)
{
    $sReviewsIds = substr($sReviewsIds, 1, strlen($sReviewsIds)-2); // enleve les virgules de début et de fin
    $aReviewsIds = explode(',', $sReviewsIds);
    if(count($aReviewsIds) == 1)
    {
        $aUserIdsToDelete[] = $user_id;
    }
}

if(!empty($aUserIdsToDelete))
{
    $sWhere = $oReviews->getAdapter()->quoteInto('user_id IN (?)', $aUserIdsToDelete);
    $oDb->delete('fr_reviewsPerUser', $sWhere);
}
echo "single review users deletion ok \n";





/*******************************
 * 2 - FILL FR_PRODUCTSPERUSER  *
 *******************************/

$oDb->exec('TRUNCATE TABLE fr_productsPerUser');

$oDb->exec('INSERT INTO fr_productsPerUser (user_id, sProductsIds)
SELECT user_id, CONCAT(",", GROUP_CONCAT(DISTINCT product_id ORDER BY product_id), ",")
FROM usersProducts
WHERE user_id < 700000
GROUP BY user_id
HAVING COUNT(*) > 1
');

// AND userProduct_iType = 0

echo "fr_productsPerUser insert ok \n";




/*********************************************************
 * 3 - CALCULATE THE SUPPORT AND CONFIDENCE OF ITEMSETS  *
**********************************************************/

$oDb->exec('TRUNCATE TABLE suggestedProducts');

// based on Apriori algorithm

$iNbReviewsMin = 2; // minimum d'avis pour les produits pris en compte
$iNbReviewsMin_2ItemsSets = 2; // minimum d'avis en commun pour qu'une paire de produits soit prise en compte
$iNbOwnersMin_2ItemsSets = 2; // minimum de owners en commun pour qu'une paire de produits soit prise en compte
$iConfidenceMinimum = 15; // minimum de % de membres aimant / ayant prod2 si prod1 pour que la paire soit prise en compte

$iAssos = 0;
// $sCsvResult = "produit 1 (connu); produit 2 (a proposer); nb membres aimant les 2; membres aimant p2 parmi ceux qui aiment p1; nb membres possedant les 2; membres possedant p2 parmi ceux qui possedent p1;\n";

// produits ayant plus de $iNbReviewsMin avis
$oQuery = $oDb->select()
               ->from('fr_reviews',array())
               ->join('fr_reviewsProducts', 'fr_reviewsProducts.review_id = fr_reviews.review_id', array('product_id'))
               ->where('review_bDeleted = 0')
               ->where('review_iAverageMark > 5')
               ->group('product_id')
               ->having('COUNT(*) >= ' . $iNbReviewsMin)
               ->order('product_id'); // nb avis minimum
$aReviewedProducts = $oDb->fetchCol($oQuery);

echo count($aReviewedProducts) . ' produits ayant plus de ' . $iNbReviewsMin . " avis \n";

$oQuery = $oDb->select()
               ->from('fr_reviews',array('fr_reviewsProducts.product_id', new Zend_Db_Expr('COUNT(*)')))
               ->join('fr_reviewsProducts', 'fr_reviewsProducts.review_id = fr_reviews.review_id', array())
               ->where('review_bDeleted = 0')
               ->where('review_iAverageMark > 5')
               ->where('product_id IN (?)', $aReviewedProducts)
               ->group('product_id'); // nb avis minimum
$aNbReviewsByProducts = $oDb->fetchPairs($oQuery);

$oQuery = $oDb->select()
               ->from('usersProducts',array('product_id', 'iCount' => new Zend_Db_Expr('COUNT(*)')))
               // ->where('userProduct_iType = 0')
               ->group('product_id')
               ->having('iCount > 1');
$aNbOwnersByProducts = $oDb->fetchPairs($oQuery);

foreach($aReviewedProducts as $i => $product_id_x)
{
    echo "  " . $product_id_x;

    $oQuery = $oDb->select()
               ->from('fr_reviewsPerUser', array('user_id', 'sReviewsIds'))
               ->where('sReviewsIds LIKE "%,' . $product_id_x . ',%"');
    $aReviewsListsPerUser = $oDb->fetchPairs($oQuery);

    $oQuery = $oDb->select()
               ->from('fr_productsPerUser', array('user_id', 'sProductsIds'))
               ->where('sProductsIds LIKE "%,' . $product_id_x . ',%"');
    $aProductsListsPerUser = $oDb->fetchPairs($oQuery);

    foreach($aReviewedProducts as $j => $product_id_y)
    {
        if($j <= $i) // le couple (x, y) est identique à (y, x)
        {
            continue;
        }

        // search for products reviews associations
        $iNbReviewsOccurence = 0;
        foreach($aReviewsListsPerUser as $sList)
        {
            if(strpos($sList, ','.$product_id_y.',') !== false)
            {
                $iNbReviewsOccurence++;
            }
        }

        // cas particulier : pas d'avis en commun -> on zappe
        if(!$iNbReviewsOccurence)
        {
            continue;
        }

        // search for products owns associations
        $iNbOwnersOccurence = 0;
        foreach($aProductsListsPerUser as $sList)
        {
            if(strpos($sList, ','.$product_id_y.',') !== false)
            {
                $iNbOwnersOccurence++;
            }
        }

        if($iNbReviewsOccurence >= $iNbReviewsMin_2ItemsSets || $iNbOwnersOccurence >= $iNbOwnersMin_2ItemsSets)
        {
            /*
            $oQuery = $oDb->select()
               ->from('products', array('product_sName'))
               ->join('productsManufacturers', 'productsManufacturers.product_id = products.product_id', array())
               ->join('manufacturers', 'manufacturers.manufacturer_id = productsManufacturers.manufacturer_id', array('manufacturer_sName'))
               ->where('products.product_id IN (?)', array($product_id_x, $product_id_y))
               ->order('products.product_id');
            $aProducts = $oDb->fetchAll($oQuery);

            // noms produits
            $sProductName1 = str_replace(';', ' ', $aProducts[0]->manufacturer_sName . ' ' . $aProducts[0]->product_sName);
            $sProductName2 = str_replace(';', ' ', $aProducts[1]->manufacturer_sName . ' ' . $aProducts[1]->product_sName);
            */

            // reviews confiance
            $iReviewConfidence1 = round($iNbReviewsOccurence * 100 / $aNbReviewsByProducts[$product_id_x]);
            $iReviewConfidence2 = round($iNbReviewsOccurence * 100 / $aNbReviewsByProducts[$product_id_y]);

            // owners confiance
            if($iNbOwnersOccurence && isset($aNbOwnersByProducts[$product_id_x]) && $aNbOwnersByProducts[$product_id_x])
            {
                $iOwnersConfidence1 = round($iNbOwnersOccurence * 100 / $aNbOwnersByProducts[$product_id_x]);
            }
            else
            {
                $iOwnersConfidence1 = 0;
            }
            if($iNbOwnersOccurence && isset($aNbOwnersByProducts[$product_id_y]) && $aNbOwnersByProducts[$product_id_y])
            {
                $iOwnersConfidence2 = round($iNbOwnersOccurence * 100 / $aNbOwnersByProducts[$product_id_y]);
            }
            else
            {
                $iOwnersConfidence2 = 0;
            }

            if($iReviewConfidence1 >= $iConfidenceMinimum || $iOwnersConfidence1 >= $iConfidenceMinimum)
            {
                //$sCsvResult .= $sProductName1.';'.$sProductName2.';'.$iNbReviewsOccurence.';'.$iReviewConfidence1.'%;'.$iNbOwnersOccurence.';'.$iOwnersConfidence1."%;\n";

                // TODO trouver un calcul de score
                $iScore = (5 * $iNbReviewsOccurence) + $iReviewConfidence1 + (5 * $iNbOwnersOccurence) + $iOwnersConfidence1;
                $oSuggestedProducts->insert(array('product_id' => $product_id_x, 'suggestedProduct_id' => $product_id_y, 'suggestedProduct_iScore' => $iScore));
                $iAssos++;
                echo '.';
            }

            if($iReviewConfidence2 >= $iConfidenceMinimum || $iOwnersConfidence2 >= $iConfidenceMinimum)
            {

                //$sCsvResult .= $sProductName2.';'.$sProductName1.';'.$iNbReviewsOccurence.';'.$iReviewConfidence2.'%;'.$iNbOwnersOccurence.';'.$iOwnersConfidence2."%;\n";

                // TODO trouver un calcul de score
                $iScore = (5 * $iNbReviewsOccurence) + $iReviewConfidence2 + (5 * $iNbOwnersOccurence) + $iOwnersConfidence2;
                $oSuggestedProducts->insert(array('product_id' => $product_id_y, 'suggestedProduct_id' => $product_id_x, 'suggestedProduct_iScore' => $iScore));
                $iAssos++;
                echo '.';
            }

        }
    }
}

$fEndTime = microtime(true);
echo "\n\n". $iAssos." assos \n";

/*
$sFileName = '../data/'.$oConfig->getDomain().'/'.$oConfig->getLanguage().'/productsAssociations.csv';
echo "\n écriture de ".$sFileName."\n";
$handle = fopen($sFileName, "w+");
$iWrite = fwrite($handle, $sCsvResult);
*/

echo "\n\nDone in " . number_format($fEndTime - $fStartTime, 2) . "s\n";