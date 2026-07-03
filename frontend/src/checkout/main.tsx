import { createRoot } from 'react-dom/client';
import '../styles.css';
import { CheckoutPage } from './Checkout';
import { ThanksPage } from './Thanks';

/**
 * Entry ligera compartida por /checkout/{slug} y /gracias (< 90KB gz:
 * sin react-query, sin framer-motion). Monta según el root presente.
 */

const checkoutRoot = document.getElementById('impay-checkout-root');
const thanksRoot = document.getElementById('impay-gracias-root');

if (checkoutRoot) {
  createRoot(checkoutRoot).render(<CheckoutPage />);
} else if (thanksRoot) {
  createRoot(thanksRoot).render(<ThanksPage />);
}
