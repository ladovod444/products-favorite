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

namespace BaksDev\Products\Favorite\Controller\Public;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Controller\AbstractController;
use BaksDev\Products\Favorite\Repository\ProductsFavoriteAll\ProductFavoriteAllResult;
use BaksDev\Products\Favorite\Repository\ProductsFavoriteAll\ProductsFavoriteAllInterface;
use BaksDev\Products\Favorite\UseCase\Public\New\AnonymousProductsFavoriteDTO;
use BaksDev\Products\Favorite\UseCase\Public\New\PublicProductsFavoriteForm;
use BaksDev\Users\User\Entity\User;
use Random\Randomizer;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class IndexController extends AbstractController
{
    #[Route('/favorites/{page<\d+>}', name: 'public.index', methods: ['GET'])]
    public function index(
        Request $request,
        ProductsFavoriteAllInterface $allProductsFavorite,
        FormFactoryInterface $formFactory,
        AppCacheInterface $cache,
        int $page = 0,
        #[MapQueryParameter] string|null $share = null,
    ): Response
    {
        // Получаем список
        $User = $this->getUsr();

        $AppCache = $cache->init('products-favorite');

        if(is_null($share) === false)
        {
            if($AppCache->hasItem($share))
            {
                $User = new User($AppCache->getItem($share)->get());
            }
        }

        if(false === ($User instanceof User))
        {
            $session = $request->getSession();
            $query = $allProductsFavorite->session($session)->findPublicPaginator();
        }
        else
        {
            $salt = new Randomizer()->getBytes(16);
            $key = md5($request->getClientIp().$request->headers->get('USER-AGENT').$salt);

            $cacheItem = $AppCache->getItem($key);
            $cacheItem->set($User->getUserIdentifier());

            $AppCache->save($cacheItem);

            $query = $allProductsFavorite
                ->user($User->getId())
                ->findUserPaginator();
        }

        $forms = [];

        /** @var ProductFavoriteAllResult $product */
        foreach($query->getData() as $product)
        {
            $invariable = (string) $product->getProductInvariableId();

            $ProductsFavoriteDTO = new AnonymousProductsFavoriteDTO()
                ->setInvariable($invariable);

            $favoriteForm = $formFactory
                ->createNamed(
                    name: $invariable,
                    type: PublicProductsFavoriteForm::class,
                    data: $ProductsFavoriteDTO,
                    options: ['action' => $this->generateUrl('products-favorite:newedit.new', ['invariable' => $invariable])],
                );

            $forms[$invariable] = $favoriteForm->createView();
        }

        return $this->render(
            [
                'query' => $query->getData(),
                'forms' => $forms,
                'share' => empty($key) === true ? null : $key,
                'is_shared' => is_null($share) === false,
            ],
        );
    }
}