-- Override manuel plan cmem (ex. "ami"), pose par un admin, hors flux Stripe.
-- Resolution effective (voir EntitlementService): stripe_subscriptions actif > cmem_plan_override > 'free'.
-- NE PAS EXECUTER SANS CONFIRMATION EXPLICITE (regle STOP migration DB, CLAUDE.md).
ALTER TABLE users ADD COLUMN cmem_plan_override VARCHAR(20) NULL;
