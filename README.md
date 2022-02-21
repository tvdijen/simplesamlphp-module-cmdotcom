![Build Status](https://github.com/tvdijen/simplesamlphp-module-cmdotcom/workflows/CI/badge.svg?branch=master)
[![Coverage Status](https://codecov.io/gh/tvdijen/simplesamlphp-module-cmdotcom/branch/master/graph/badge.svg)](https://codecov.io/gh/tvdijen/simplesamlphp-module-cmdotcom)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/tvdijen/simplesamlphp-module-cmdotcom/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/tvdijen/simplesamlphp-module-cmdotcom/?branch=master)

SMS as Second Factor module
===========================

<!-- {{TOC}} -->


This module is implemented as an Authentication Processing Filter. That 
means it can be configured in the global config.php file or the SP remote or 
IdP hosted metadata.

It is recommended to run the module at the IdP, and configure the filter to run after all attribute mangling
filters have completed, to show the user the exact same attributes that are sent to the SP.

  * [Read more about processing filters in SimpleSAMLphp](simplesamlphp-authproc)


Prerequisites
-------------

To be able to use this module, you have to register at CM.com to get an API-key for their RESTful API.


How to setup the module
-----------------------

First you need to enable the module; in `config.php`, search for the
`module.enable` key and add `cmdotcom` with value `true`:

```
    'module.enable' => [
         'cmdotcom' => true,
         …
    ],
```

In order to proces the passcode SMS in this module, you need set the mandatory API-key
to interact with the CM.com RESTful API in the `productToken` setting.

You can optionally set the `mobilePhoneAttribute` to the name of the attribute that
contains the user's mobile phone number. The default attribute if this setting is left out is `mobile`.

If the attribute defined above is not available for a user, an error message will be shown,
and the user will not be allowed through the filter. So make sure that you select an attribute that is available to all users.

By default the SMS will originate from `Example`, but this can be changed using the optional `originator` setting.
The maximum length is 16 digits for a phonenumber or 11 alphanumerical characters [a-zA-Z0-9]. Example: 'CMTelecom'.

Another default is that the OTP received by SMS can be entered within a period of three minutes. This can
be adjusted by configuring the optional `validFor` setting to the number of seconds the code should be valid.

Finally, it is possible for the OTP code to be automatically pushed to a mobile app. To do this, set the
optional `allowPush` to `true` and set the `appKey` to match your mobile app.

Add the filter to your Identity Provider hosted metadata authproc filters
list, specifying the attribute you've selected.

```
    90 => [
        'class' => 'cmdotcom:OTP',
        'productToken' => 'secret',
        'mobilePhoneAttribute' => 'mobile',
        'originator' => 'CM Telecom',
        'validFor' => 600,
    ],
```

This setup uses no persistent storage at all. This means that the user will
always be asked to enter a passcode each time she logs in.
