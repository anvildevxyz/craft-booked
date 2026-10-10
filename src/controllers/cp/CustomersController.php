<?php

namespace anvildev\booked\controllers\cp;

use anvildev\booked\Booked;
use anvildev\booked\services\CustomerService;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use yii\web\NotFoundHttpException;

/**
 * The customer list and one customer's booking history.
 *
 * Gated on viewing bookings rather than a permission of its own: this shows the
 * same records the bookings index does, grouped differently, so a separate
 * permission would only create a way to grant one and not the other.
 */
class CustomersController extends Controller
{
    private const PER_PAGE = 50;

    public function init(): void
    {
        parent::init();
        $this->requirePermission('booked-viewBookings');
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();

        $search = trim((string)$request->getParam('search', ''));
        $sort = (string)$request->getParam('sort', 'lastBooking');
        $dir = $request->getParam('dir') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int)$request->getParam('page', 1));

        $result = $this->customers()->search(
            $search,
            $sort,
            $dir,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
        );

        return $this->renderTemplate('booked/customers/_index', [
            'customers' => $result['customers'],
            'total' => $result['total'],
            'search' => $search,
            'sort' => in_array($sort, CustomerService::SORTABLE, true) ? $sort : 'lastBooking',
            'dir' => $dir,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'totalPages' => (int)ceil($result['total'] / self::PER_PAGE),
        ]);
    }

    /**
     * Yii has already percent-decoded the route parameter by the time it lands
     * here, so it must not be decoded again: `urldecode()` reads `+` as a space,
     * and `first+tag@example.com` would silently become a different address that
     * matches nobody. Plus-addressing is exactly what people book with.
     */
    public function actionDetail(?string $email = null): Response
    {
        $email = trim($email ?? (string)Craft::$app->getRequest()->getParam('email', ''));

        if ($email === '') {
            throw new NotFoundHttpException(Craft::t('booked', 'customers.notFound'));
        }

        $customer = $this->customers()->get($email);
        if (!$customer) {
            throw new NotFoundHttpException(Craft::t('booked', 'customers.notFound'));
        }

        // Linking to a Craft user's name, email and edit page is new exposure
        // this view adds beyond what booked-viewBookings grants elsewhere —
        // gate it on the Users-section permission rather than handing every
        // viewer of bookings a deep link into the Users CP area.
        $user = Craft::$app->getUser()->getIdentity();
        $canViewAccount = $user && ($user->admin || $user->can('editUsers'));
        $linkedUser = $canViewAccount ? $this->customers()->linkedUser($customer['userId'] ?? null) : null;

        return $this->renderTemplate('booked/customers/detail', [
            'customer' => $customer,
            'bookings' => $this->customers()->bookingsFor($email),
            'linkedUser' => $linkedUser,
            'hasLinkedAccount' => (bool)($customer['userId'] ?? null),
        ]);
    }

    private function customers(): CustomerService
    {
        return Booked::getInstance()->getCustomers();
    }
}
