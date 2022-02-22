# SMS as Second Factor module

![Build Status](https://github.com/tvdijen/simplesamlphp-module-cmdotcom/workflows/CI/badge.svg?branch=master)
[![Coverage Status](https://codecov.io/gh/tvdijen/simplesamlphp-module-cmdotcom/branch/master/graph/badge.svg)](https://codecov.io/gh/tvdijen/simplesamlphp-module-cmdotcom)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/tvdijen/simplesamlphp-module-cmdotcom/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/tvdijen/simplesamlphp-module-cmdotcom/?branch=master)
[![Type Coverage](https://shepherd.dev/github/tvdijen/simplesamlphp-module-cmdotcom/coverage.svg)](https://shepherd.dev/github/tvdijen/simplesamlphp-module-cmdotcom)
[![Psalm Level](https://shepherd.dev/github/tvdijen/simplesamlphp-module-cmdotcom/level.svg)](https://shepherd.dev/github/tvdijen/simplesamlphp-module-cmdotcom)

<!-- {{TOC}} -->

This module is implemented as an Authentication Processing Filter. That
means it can be configured in the global config.php file or the SP remote or
IdP hosted metadata.

* [Read more about processing filters in SimpleSAMLphp](simplesamlphp-authproc)

## Prerequisites

To be able to use this module, you have to register at CM.com to
get an API-key for their RESTful API.

## How to setup the module

First you need to enable the module; in `config.php`, search for the
`module.enable` key and add `cmdotcom` with value `true`:

```php
    'module.enable' => [
         'cmdotcom' => true,
         …
    ],
```

In order to proces the passcode SMS in this module, you need set the
mandatory API-key
to interact with the CM.com RESTful API in the `productToken` setting.

You can optionally set the `mobilePhoneAttribute` to the name of the attribute
that contains the user's mobile phone number. The default attribute if this
setting is left out is `mobile`.

If the attribute defined above is not available for a user, an error message
will be shown, and the user will not be allowed through the filter.
Please make sure that you select an attribute that is available to all users.

By default the SMS will originate from `Example`, but this can be changed
using the optional `originator` setting. The maximum length is 16 digits for
a phonenumber or 11 alphanumerical characters [a-zA-Z0-9].
Example: 'CMTelecom'.

Another default is that the OTP received by SMS can be entered within a
period of three minutes. This can be adjusted by configuring the optional
`validFor` setting to the number of seconds the code should be valid.

Finally, it is possible for the OTP code to be automatically pushed to a
mobile app. To do this, set the optional `allowPush` to `true` and set the
`appKey` to match your mobile app.

This module is using `[libphonenumber-for-php][giggsey/libphonenumber-for-php]`
to parse recipient phonenumbers and normalize them. If you experience
undeliverable SMS, you can try to set your `defaultRegion` to the
[CLDR] two-letter region-code format for your region.

[libphonenumber-for-php]: https://github.com/giggsey/libphonenumber-for-php
[CLDR]: https://www.unicode.org/cldr/cldr-aux/charts/30/supplemental/territory_information.html

Add the filter to your Identity Provider hosted metadata authproc filters
list, specifying the attribute you've selected.

```php
    90 => [
        'class' => 'cmdotcom:OTP',
        'productToken' => 'secret',
        'mobilePhoneAttribute' => 'mobile',
        'originator' => 'CM Telecom',
        'validFor' => 600,
        'defaultRegion' => 'NL',
    ],
```

This setup uses no persistent storage at all. This means that the user will
always be asked to enter a passcode each time she logs in.
