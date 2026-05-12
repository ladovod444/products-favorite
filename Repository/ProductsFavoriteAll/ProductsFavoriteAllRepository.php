<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 *
 */

declare(strict_types=1);

namespace BaksDev\Products\Favorite\Repository\ProductsFavoriteAll;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Core\Services\Paginator\PaginatorInterface;
use BaksDev\Products\Category\Entity\CategoryProduct;
use BaksDev\Products\Category\Entity\Info\CategoryProductInfo;
use BaksDev\Products\Category\Entity\Offers\CategoryProductOffers;
use BaksDev\Products\Category\Entity\Offers\Variation\CategoryProductVariation;
use BaksDev\Products\Category\Entity\Offers\Variation\Modification\CategoryProductModification;
use BaksDev\Products\Favorite\Entity\ProductsFavorite;
use BaksDev\Products\Product\Entity\Category\ProductCategory;
use BaksDev\Products\Product\Entity\Event\ProductEvent;
use BaksDev\Products\Product\Entity\Info\ProductInfo;
use BaksDev\Products\Product\Entity\Offers\Image\ProductOfferImage;
use BaksDev\Products\Product\Entity\Offers\Price\ProductOfferPrice;
use BaksDev\Products\Product\Entity\Offers\ProductOffer;
use BaksDev\Products\Product\Entity\Offers\Quantity\ProductOfferQuantity;
use BaksDev\Products\Product\Entity\Offers\Variation\Image\ProductVariationImage;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\Image\ProductModificationImage;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\Price\ProductModificationPrice;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\ProductModification;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\Quantity\ProductModificationQuantity;
use BaksDev\Products\Product\Entity\Offers\Variation\Price\ProductVariationPrice;
use BaksDev\Products\Product\Entity\Offers\Variation\ProductVariation;
use BaksDev\Products\Product\Entity\Offers\Variation\Quantity\ProductVariationQuantity;
use BaksDev\Products\Product\Entity\Photo\ProductPhoto;
use BaksDev\Products\Product\Entity\Price\ProductPrice;
use BaksDev\Products\Product\Entity\Product;
use BaksDev\Products\Product\Entity\ProductInvariable;
use BaksDev\Products\Product\Entity\Project\ProductProject;
use BaksDev\Products\Product\Entity\Project\Season\ProductProjectSeason;
use BaksDev\Products\Product\Entity\Trans\ProductTrans;
use BaksDev\Products\Promotion\BaksDevProductsPromotionBundle;
use BaksDev\Products\Promotion\Entity\Event\Invariable\ProductPromotionInvariable;
use BaksDev\Products\Promotion\Entity\Event\Period\ProductPromotionPeriod;
use BaksDev\Products\Promotion\Entity\Event\Price\ProductPromotionPrice;
use BaksDev\Products\Promotion\Entity\ProductPromotion;
use BaksDev\Products\Stocks\BaksDevProductsStocksBundle;
use BaksDev\Products\Stocks\Entity\Total\ProductStockTotal;
use BaksDev\Reference\Region\Type\Id\RegionUid;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Discount\UserProfileDiscount;
use BaksDev\Users\Profile\UserProfile\Entity\Event\Region\UserProfileRegion;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\User\Entity\User;
use BaksDev\Users\User\Type\Id\UserUid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\Session;

final class ProductsFavoriteAllRepository implements ProductsFavoriteAllInterface
{
    private Session $session;

    private UserUid|false $usr = false;

    public function __construct(
        private readonly DBALQueryBuilder $DBALQueryBuilder,
        private readonly PaginatorInterface $paginator,
        #[Autowire(env: 'PROJECT_REGION')] private readonly ?string $region = null,
    ) {}

    public function user(User|UserUid|string $usr): self
    {
        if(is_string($usr))
        {
            $usr = new UserUid($usr);
        }

        if($usr instanceof User)
        {
            $usr = $usr->getId();
        }

        $this->usr = $usr;

        return $this;
    }

    public function session(Session $session): self
    {
        $this->session = $session;
        return $this;
    }

    /** Метод возвращает пагинатор ProductsFavorite */
    public function findUserPaginator(): PaginatorInterface
    {

        if(false === $this->usr)
        {
            throw new InvalidArgumentException('Invalid Argument User');
        }

        $dbal = $this->builder();

        $dbal
            ->from(ProductsFavorite::class, 'favorite')
            ->where('favorite.usr = :usr')
            ->setParameter('usr', $this->usr, UserUid::TYPE);

        $dbal
            ->addSelect('product_invariable.id as product_invariable_id')
            ->addSelect('product_invariable.offer AS product_invariable_offer_const')
            ->join(
                'favorite',
                ProductInvariable::class,
                'product_invariable',
                'product_invariable.id = favorite.invariable',
            );

        $dbal->allGroupByExclude();

        return $this->paginator->fetchAllHydrate($dbal, ProductFavoriteAllResult::class);
    }

    private function builder(): DBALQueryBuilder
    {
        $dbal = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();

        $dbal
            ->addSelect('product.id AS product_id')
            ->addSelect('product.event AS product_event')
            ->leftJoin(
                'product_invariable',
                Product::class,
                'product',
                'product.id = product_invariable.product',
            );

        /** OFFER */
        $dbal
            ->addSelect('product_offer.id AS product_offer_id')
            ->addSelect('product_offer.const AS product_offer_const')
            ->addSelect("product_offer.value as product_offer_value")
            ->addSelect("product_offer.postfix as product_offer_postfix")
            ->leftJoin(
                'product_invariable',
                ProductOffer::class,
                'product_offer',
                'product_offer.event = product.event AND product_offer.const = product_invariable.offer',
            );

        /** Получаем тип торгового предложения */
        $dbal
            ->addSelect('category_offer.reference as product_offer_reference')
            ->leftJoin(
                'product_offer',
                CategoryProductOffers::class,
                'category_offer',
                'category_offer.id = product_offer.category_offer',
            );

        /** VARIATION */
        $dbal
            ->addSelect('product_variation.id AS product_variation_id')
            ->addSelect('product_variation.const AS product_variation_const')
            ->addSelect("product_variation.value as product_variation_value")
            ->addSelect("product_variation.postfix as product_variation_postfix")
            ->leftJoin(
                'product_offer',
                ProductVariation::class,
                'product_variation',
                'product_variation.offer = product_offer.id AND product_variation.const = product_invariable.variation',
            );

        /** Получаем тип множественного варианта */
        $dbal
            ->addSelect('category_offer_variation.reference as product_variation_reference')
            ->leftJoin(
                'product_variation',
                CategoryProductVariation::class,
                'category_offer_variation',
                'category_offer_variation.id = product_variation.category_variation',
            );

        /** MODIFICATION */
        $dbal
            ->addSelect('product_modification.id AS product_modification_id')
            ->addSelect('product_modification.const AS product_modification_const')
            ->addSelect("product_modification.value as product_modification_value")
            ->addSelect("product_modification.postfix as product_modification_postfix   ")
            ->leftJoin(
                'product_variation',
                ProductModification::class,
                'product_modification',
                'product_modification.variation = product_variation.id AND product_modification.const = product_invariable.modification',
            );

        /** Получаем тип множественного варианта */
        $dbal
            ->addSelect('category_offer_modification.reference as product_modification_reference')
            ->leftJoin(
                'product_modification',
                CategoryProductModification::class,
                'category_offer_modification',
                'category_offer_modification.id = product_modification.category_modification',
            );

        /**  Название */
        $dbal
            ->addSelect('product_trans.name AS product_trans_name')
            ->leftJoin(
                'product',
                ProductTrans::class,
                'product_trans',
                'product_trans.event = product.event AND product_trans.local = :local',
            );

        /** Фото */

        $dbal
            ->leftJoin(
                'product',
                ProductPhoto::class,
                'product_photo',
                'product_photo.event = product.event AND product_photo.root = TRUE',
            );

        $dbal
            ->leftJoin(
                'product_offer',
                ProductOfferImage::class,
                'product_offer_images',
                'product_offer_images.offer = product_offer.id AND product_offer_images.root = TRUE',
            );

        $dbal
            ->leftJoin(
                'product_variation',
                ProductVariationImage::class,
                'product_variation_images',
                'product_variation_images.variation = product_variation.id AND product_variation_images.root = TRUE',
            );

        $dbal
            ->leftJoin(
                'product_modification',
                ProductModificationImage::class,
                'product_modification_images',
                'product_modification_images.modification = product_modification.id AND product_modification_images.root = TRUE',
            );

        $dbal->addSelect(
            "JSON_AGG 
            (DISTINCT
				CASE 
                    WHEN product_offer_images.ext IS NOT NULL 
                    THEN JSONB_BUILD_OBJECT
                        (
                            'img_root', product_offer_images.root,
                            'img', CONCAT ( '/upload/".$dbal->table(ProductOfferImage::class)."' , '/', product_offer_images.name),
                            'img_ext', product_offer_images.ext,
                            'img_cdn', product_offer_images.cdn
                        ) 
                    WHEN product_variation_images.ext IS NOT NULL 
                    THEN JSONB_BUILD_OBJECT
                        (
                            'img_root', product_variation_images.root,
                            'img', CONCAT ( '/upload/".$dbal->table(ProductVariationImage::class)."' , '/', product_variation_images.name),
                            'img_ext', product_variation_images.ext,
                            'img_cdn', product_variation_images.cdn
                        )	
                    WHEN product_modification_images.ext IS NOT NULL 
                    THEN JSONB_BUILD_OBJECT
                        (
                            'img_root', product_modification_images.root,
                            'img', CONCAT ( '/upload/".$dbal->table(ProductModificationImage::class)."' , '/', product_modification_images.name),
                            'img_ext', product_modification_images.ext,
                            'img_cdn', product_modification_images.cdn
                        )
                    WHEN product_photo.ext IS NOT NULL 
                    THEN JSONB_BUILD_OBJECT
                        (
                            'img_root', product_photo.root,
                            'img', CONCAT ( '/upload/".$dbal->table(ProductPhoto::class)."' , '/', product_photo.name),
                            'img_ext', product_photo.ext,
                            'img_cdn', product_photo.cdn
                        )
                    END) AS product_images",
        );


        /** Цена */
        /* Базовая Цена товара */
        $dbal->leftJoin(
            'product',
            ProductPrice::class,
            'product_price',
            'product_price.event = product.event',
        );

        /* Цена торгового предложения */
        $dbal->leftJoin(
            'product_offer',
            ProductOfferPrice::class,
            'product_offer_price',
            'product_offer_price.offer = product_offer.id',
        );

        /* Цена множественного варианта */
        $dbal->leftJoin(
            'product_variation',
            ProductVariationPrice::class,
            'product_variation_price',
            'product_variation_price.variation = product_variation.id',
        );

        /* Цена модификации множественного варианта */
        $dbal->leftJoin(
            'product_modification',
            ProductModificationPrice::class,
            'product_modification_price',
            'product_modification_price.modification = product_modification.id',
        );

        /** Наличие для добавления в корзину */
        /* Наличие и резерв торгового предложения */
        $dbal
            ->leftJoin(
                'product_offer',
                ProductOfferQuantity::class,
                'product_offer_quantity',
                'product_offer_quantity.offer = product_offer.id',
            );

        /* Наличие и резерв множественного варианта */
        $dbal
            ->leftJoin(
                'product_variation',
                ProductVariationQuantity::class,
                'product_variation_quantity',
                'product_variation_quantity.variation = product_variation.id',
            );

        $dbal
            ->leftJoin(
                'product_modification',
                ProductModificationQuantity::class,
                'product_modification_quantity',
                'product_modification_quantity.modification = product_modification.id',
            );

        $dbal
            ->addSelect("product_event.id AS product_event")
            ->leftJoin(
                'product',
                ProductEvent::class,
                'product_event',
                'product_event.id = product.event',
            );

        /* Категория товара */
        $dbal
            ->addSelect("product_category.category AS product_category")
            ->leftJoin(
                'product_event',
                ProductCategory::class,
                'product_category',
                'product_category.event = product_event.id AND product_category.root IS TRUE',
            );

        $dbal
            ->addSelect("category.event AS category_event")
            ->leftJoin(
                'product_event',
                CategoryProduct::class,
                'category',
                'category.id = product_category.category',
            );

        $dbal
            ->addSelect('category_info.url AS category_url')
            ->leftJoin(
                'category',
                CategoryProductInfo::class,
                'category_info',
                'category_info.event = category.event AND category_info.active IS TRUE',
            );

        $dbal
            ->addSelect('product_info.url AS product_url')
            ->leftJoin(
                'product',
                ProductInfo::class,
                'product_info',
                'product_info.product = product.id',
            );

        /* Получить товарную наценку (скидку) по сезонности с учетом текущего месяца */
        $dbal
            ->leftJoin(
                'product_invariable',
                ProductProject::class,
                'product_project',
                '
                    product_project.product = product_invariable.product
                    '.(true === $dbal->bindProjectProfile()
                    ? 'AND product_project.profile = :'.$dbal::PROJECT_PROFILE_KEY
                    : 'AND product_project.profile IS NULL'),
            );

        $dbal
            ->addSelect('product_project_season.percent as season_percent')
            ->leftJoin(
                'product_project',
                ProductProjectSeason::class,
                'product_project_season',
                'product_project_season.project = product_project.id
                     AND product_project_season.month = :month',
            )
            ->setParameter(
                key: 'month',
                value: (int) date('n'),
                type: ParameterType::INTEGER,
            );

        /**
         * ProductsPromotion
         */
        if(true === class_exists(BaksDevProductsPromotionBundle::class) && true === $dbal->isProjectProfile())
        {
            $dbal
                ->leftJoin(
                    'product_invariable',
                    ProductPromotionInvariable::class,
                    'product_promotion_invariable',
                    '
                        product_promotion_invariable.product = product_invariable.id
                        AND product_promotion_invariable.profile = :'.$dbal::PROJECT_PROFILE_KEY,
                );

            $dbal
                ->leftJoin(
                    'product_promotion_invariable',
                    ProductPromotion::class,
                    'product_promotion',
                    'product_promotion.id = product_promotion_invariable.main',
                );

            $dbal
                ->addSelect('product_promotion_price.value AS promotion_price')
                ->leftJoin(
                    'product_promotion',
                    ProductPromotionPrice::class,
                    'product_promotion_price',
                    'product_promotion_price.event = product_promotion.event',
                );

            $dbal
                ->addSelect('
                CASE
                    WHEN 
                        CURRENT_DATE >= product_promotion_period.date_start
                        AND
                         (
                            product_promotion_period.date_end IS NULL OR CURRENT_DATE <= product_promotion_period.date_end
                         )
                    THEN true
                    ELSE false
                END AS promotion_active
            ')
                ->leftJoin(
                    'product_promotion',
                    ProductPromotionPeriod::class,
                    'product_promotion_period',
                    '
                        product_promotion_period.event = product_promotion.event',
                );
        }

        /** Персональная скидка из профиля авторизованного пользователя */
        if(true === $dbal->bindCurrentProfile())
        {

            $dbal
                ->join(
                    'product',
                    UserProfile::class,
                    'current_profile',
                    '
                        current_profile.id = :'.$dbal::CURRENT_PROFILE_KEY,
                );

            $dbal
                ->addSelect('current_profile_discount.value AS profile_discount')
                ->leftJoin(
                    'current_profile',
                    UserProfileDiscount::class,
                    'current_profile_discount',
                    '
                        current_profile_discount.event = current_profile.event
                        ',
                );
        }

        /**
         * Наличие продукции на складе (необходимо для отображения кнопки "в корзину")
         * Если подключен модуль складского учета и передан идентификатор профиля
         */

        if(false === empty($this->region) && class_exists(BaksDevProductsStocksBundle::class))
        {
            /* Получить все профили данного региона */

            $dbal
                ->leftJoin(
                    'product_invariable',
                    UserProfileRegion::class,
                    'product_profile_region',
                    'product_profile_region.value = :region',
                )
                ->setParameter(
                    key: 'region',
                    value: $this->region,
                    type: RegionUid::TYPE,
                );

            $dbal
                ->join(
                    'product_profile_region',
                    UserProfile::class,
                    'product_region_total',
                    'product_region_total.event = product_profile_region.event',
                );

            $dbal
                ->addSelect("JSON_AGG (
                        DISTINCT JSONB_BUILD_OBJECT (
                            'total', stock.total,
                            'reserve', stock.reserve
                        )) FILTER (WHERE stock.total > stock.reserve)

                        AS product_quantity_stocks",
                )
                ->leftJoin(
                    'product_region_total',
                    ProductStockTotal::class,
                    'stock',
                    '
                    stock.profile = product_region_total.id AND
                    stock.product = product.id

                    AND

                        CASE
                            WHEN product_offer.const IS NOT NULL
                            THEN stock.offer = product_offer.const
                            ELSE stock.offer IS NULL
                        END

                    AND

                        CASE
                            WHEN product_variation.const IS NOT NULL
                            THEN stock.variation = product_variation.const
                            ELSE stock.variation IS NULL
                        END

                    AND

                        CASE
                            WHEN product_modification.const IS NOT NULL
                            THEN stock.modification = product_modification.const
                            ELSE stock.modification IS NULL
                        END
      
                ');

        }

        /** Общая скидка (наценка) из профиля магазина */
        if(true === $dbal->bindProjectProfile())
        {

            $dbal
                ->join(
                    'product',
                    UserProfile::class,
                    'project_profile',
                    '
                        project_profile.id = :'.$dbal::PROJECT_PROFILE_KEY,
                );

            $dbal
                ->addSelect('project_profile_discount.value AS project_discount')
                ->leftJoin(
                    'project_profile',
                    UserProfileDiscount::class,
                    'project_profile_discount',
                    '
                        project_profile_discount.event = project_profile.event',
                );
        }

        $dbal->addSelect("
			COALESCE(
                NULLIF(product_modification_quantity.quantity, 0),
                NULLIF(product_variation_quantity.quantity, 0),
                NULLIF(product_offer_quantity.quantity, 0),
                NULLIF(product_price.quantity, 0),
                0
            ) AS product_quantity
		");

        $dbal->addSelect("
			COALESCE(
                NULLIF(product_modification_quantity.reserve, 0),
                NULLIF(product_variation_quantity.reserve, 0),
                NULLIF(product_offer_quantity.reserve, 0),
                NULLIF(product_price.reserve, 0),
                0
            ) AS product_reserve
		");

        /** Стоимость продукта */
        $dbal->addSelect(
            '
			CASE
			   WHEN product_modification_price.price IS NOT NULL AND product_modification_price.price > 0 
			   THEN product_modification_price.price
			   
			   WHEN product_variation_price.price IS NOT NULL AND product_variation_price.price > 0 
			   THEN product_variation_price.price
			   
			   WHEN product_offer_price.price IS NOT NULL AND product_offer_price.price > 0 
			   THEN product_offer_price.price
			   
			   WHEN product_price.price IS NOT NULL AND product_price.price > 0 
			   THEN product_price.price
			   
			   ELSE NULL
			END AS product_price
		');

        /** Предыдущая стоимость продукта */
        $dbal->addSelect("
			COALESCE(
                NULLIF(product_modification_price.old, 0),
                NULLIF(product_variation_price.old, 0),
                NULLIF(product_offer_price.old, 0),
                NULLIF(product_price.old, 0),
                0
            ) AS product_old_price
		");

        /** Валюта продукта */
        $dbal->addSelect("
			CASE
			   WHEN COALESCE(product_modification_price.price, 0) != 0 
			   THEN product_modification_price.currency
			   
			   WHEN COALESCE(product_variation_price.price, 0) != 0 
			   THEN product_variation_price.currency
			   
			   WHEN COALESCE(product_offer_price.price, 0) != 0 
			   THEN product_offer_price.currency
			   
			   WHEN COALESCE(product_price.price, 0) != 0 
			   THEN product_price.currency
			   
			   ELSE NULL
			END AS product_currency",
        );

        return $dbal;
    }

    public function findPublicPaginator(): PaginatorInterface
    {
        $favoriteProducts = $this->session->get('favorite') ?? [];

        $dbal = $this->builder();

        $dbal
            ->addSelect('product_invariable.id as product_invariable_id')
            ->addSelect('product_invariable.offer AS product_invariable_offer_const')
            ->from(ProductInvariable::class, 'product_invariable')
            ->where('product_invariable.id IN (:favoriteProducts)')
            ->setParameter('favoriteProducts', array_values($favoriteProducts), ArrayParameterType::STRING);

        $dbal->allGroupByExclude();

        return $this->paginator->fetchAllHydrate($dbal, ProductFavoriteAllResult::class);
    }

    public function analyze(): void
    {
        $this->builder()->analyze();
    }
}
