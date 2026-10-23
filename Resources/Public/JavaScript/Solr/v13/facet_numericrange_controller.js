// Solr Shield: copy of EXT:solr 13's facet_numericrange_controller.js. Changes are marked "Solr Shield".
//
// The facet URL template arrives inside the signed `_ss` payload, so the range
// placeholders have to be filled in there. Navigating through a link click
// instead of assigning window.location lets solr-shield.js attach `_solr_bot`.
function solrShieldFillUrl(template, values) {
  const url = new URL(template, window.location.href);
  const signed = url.searchParams.get('_ss');
  const fill = (subject, encode) => Object.keys(values).reduce(
    (result, placeholder) => result.replace(placeholder, encode(String(values[placeholder]))),
    subject
  );
  if (signed === null) {
    return fill(template, (value) => value);
  }
  const payload = JSON.parse(atob(signed));
  payload.p = fill(payload.p, encodeURIComponent);
  url.searchParams.set('_ss', btoa(JSON.stringify(payload)));
  return url.toString();
}

function solrShieldNavigate(url) {
  const link = document.createElement('a');
  link.href = url;
  link.hidden = true;
  document.body.appendChild(link);
  link.click();
  link.remove();
}

/**
 * The Controller. Controller responds to user actions and
 * invokes changes on the model.
 */
function NumericRangeFacetController() {
    var _this = this;

    this.init = function () {
        jQuery(".facet-type-numericRange-data").each(function () {
            var facetName = jQuery(this).data("facet-name");
            var rangeMin = jQuery(this).data("range-min");
            var rangeMax = jQuery(this).data("range-max");
            var rangeMinSelected = jQuery(this).data("range-min-selected");
            var rangeMaxSelected = jQuery(this).data("range-max-selected");
            var urlTemplate = jQuery(this).data("facet-url");

            if (rangeMinSelected === 0 && rangeMaxSelected === 0) {
                rangeMinSelected = rangeMin;
                rangeMaxSelected = rangeMax;
            }

            var rangeGap = jQuery(this).data("range-gap");

            jQuery(this).slider({
                range: true,
                values: [ rangeMinSelected, rangeMaxSelected],
                min: rangeMin,
                max: rangeMax,
                step: rangeGap,
                slide: function (event, ui) {
                    min = ui.values[0];
                    max = ui.values[1];
                    if (isNaN(min)) { min = 0; }
                    if (isNaN(max)) { max = 0; }

                    jQuery("#facet-" + facetName + "-value").html(min.toString() + "-" + max.toString());
                },
                stop: function (event, ui) {
                    min = ui.values[0];
                    max = ui.values[1];
                    if (isNaN(min)) { min = 0; }
                    if (isNaN(max)) { max = 0; }

                    // Solr Shield
                    url = solrShieldFillUrl(urlTemplate, { ___FROM___: min, ___TO___: max });
                    _this.load(url);
                }
            });
        });
    };

    var timers = {};

    this.load = function (url) {
        clearTimeout(timers[url]);
        loadResult = function (url) {
            // Solr Shield
            solrShieldNavigate(url);
        };
        timers[url] = setTimeout(loadResult(url), 1500);
    };
}

jQuery(document).ready(function () {
    var numericRangeFacetController = new NumericRangeFacetController();
    numericRangeFacetController.init();

    jQuery("body").on("tx_solr_updated", function() {
        numericRangeFacetController.init();
    });
});
