# Changelog

All notable changes to this project will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



### v2.5.0:
- feat: added tarjeta naranja to credit card list

### v2.5.1:
- feat: added version to header requests
- feat: added module version to system configuration

### v2.5.2:
- fix: saving cc_number masked to additional information

### v2.5.3:
- fix: array first included only in deve dependency, change to the class

### v2.5.4:
- feat: remove from validation tarjeta naranja

### v2.5.5:
- Fix error in general settings

### v2.5.6:
- Change shipping delivery node for BNPL
- Change tokenize route
- Change payments routes from payments/v1 to v1/payments
- Change antifraud routes from antifraud/v1 to v1/antifraud

### v2.5.7:
- Fix error when the refund didn't return JSON response

### v2.5.8:
- Fix: 'Opened' transactions with 'Failed' Callback was remaining pending
- Fiz: 'Opened' transactions with 'Capture' Callback wasn't creating the invoice automatically
- Feat: Button to fetch info when order is Opened

### v2.5.9:
- Fix: Sandbox for tokenize

### v2.6.0
- Feat: Added new options for installment rules to show only the text or the text with the installments
- Feat: Possibility to show more than one installment rules with the same installment number
- Fix: fallback to use payments URL for tokenize
- Fix: error when creating a refund that was dispatching an error even when the refund was successful

### v2.6.1
- Feat: Save Refund request and response when order transaction rollbacks
- Feat: add specific user agent for sandbox transactions

### v2.6.2
- Feat: Use default private key async requests

### v2.6.3
- Fix: Use hash to async requests

### v2.6.4
- Fix: using wrong hash when multi store

### v2.6.5
- Feat: allow fetch info for authorized orders

### v2.6.6
- Fix: error disabling logon on checkout

### v2.6.7
- Fix: add lock verification to notification callback

### v2.6.8
- Fix: Lock is not necessary anymore
- Feat: added a log when the order pass through the sales_order_save_after event

### v2.6.9
- Fix: error, rule title being shwon if the rule was already on quote

### v2.6.10
- Feat: change token url for production environments

### v2.6.11
- Fix: refund autocapture order when capture has an status code error

### v2.6.12
- Fix: saving the current rule wans't working

### v2.7.2
- Feat: added new settings for auto capture orders
- Feat: added tuys as a card brand option
- Fix: Config Status for automatically captured orders

### v2.7.3
- Fix: Paid config orders was using wrong config

### v2.7.4
- Feat: Update BNPL Banner
- Fix: send region code instead of region text for addresses

### v2.7.5
- Fix: send region code instead of region text for addresses also for BNPL

### v2.7.6
- Fix: send only number postcode for addresses in BNPL
- Fix: send number as quantity for transactions

### v2.7.7
- Fix: callback url for admin orders 

### v2.7.8
- Fix: send only number for brazilian doc numbers CPF and CNPJ

### v2.7.9
- Fix: error when unserializing empty request body on callbacks

### v2.8.0
- Feat: added timer for pix payments

### v2.8.1
- Refactor: improved performance on checkout installment rules
- Refactor: removed arrow function for compatibility with PHP 7.1, 7.2 and 7.3
- Fix: save installment options on BNPL callbacks

### v2.9.0
- Feat: added 3DSv2 support
- Feat: added new languages files, es_CO and es_MX

### v2.10.0
- Feat: Installments rules have priorities
- Feat: Config to no use default installments and use installments rules only, instead
- Feat: Use customer group to filter installments rules
- Feat: Use Product Set ID to filter installments rules
- Feat: Use day of the week for installments rules
- Feat: Use credit card brand for installments rules

### v2.10.1
- Fix: error when filtering installments rules by product set

### v2.11.0
- Feat: added 3DS feature to credit card
- Added new rules for installments

### v2.11.1
- Fix: remove unused extension attributes

### v2.11.2
- Fix: new link to BNPL checkout's banner

### v2.11.3
- Fix: Antifraud notification was not being sent to all events
- Fix: Callback notification was creating offline invoice

### v2.11.5
- Fix: Error when sending order to fraud analysis and there's not currency code on the order

### v2.12.0
- Feat: Widget Banner for BNPL
- Feat: Button Buy with Pix Parcelado with options to show on product page and cart
- Feat: PCI Compliance option for credit card payments, new config in credit card system configuration

### v2.12.1
- Fix: Adjust endpoints for PCI Credit Card Form

### v2.13.0:
- feat: Creating a bnpl modal after payment fail
- feat: Implement the antifraud strategies
- fix: installments error when saving data
