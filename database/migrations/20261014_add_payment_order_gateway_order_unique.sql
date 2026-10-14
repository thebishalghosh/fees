-- Add only after confirming payment_orders has no duplicate non-null
-- (gateway, gateway_order_id) pairs. Roll back with:
-- ALTER TABLE payment_orders DROP INDEX uq_payment_orders_gateway_order;
ALTER TABLE payment_orders
    ADD UNIQUE KEY uq_payment_orders_gateway_order (gateway, gateway_order_id);
