// Solr Shield: copy of EXT:solr 13's facet_daterange_controller.js. Changes are marked "Solr Shield".
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
function DateRangeFacetController() {
    var _this = this;

    this.init = function() {
        dateSelector = jQuery(".dateselector");
        dateSelector.datepicker();
        dateSelector.change(function(){ _this.solrRequest("created"); });
    };

    this.solrRequest = function(facetName) {
        startDate = jQuery('#start_date_'+facetName);
        endDate = jQuery('#end_date_'+facetName);
        if (startDate.val() !== '' &&  endDate.val() !== '' ) {
            url = jQuery('#' + facetName + '_url').val();
            start_date = _this.convertToDate(startDate.datepicker('getDate'));
            end_date = _this.convertToDate(endDate.datepicker('getDate'));

            // Solr Shield
            url = solrShieldFillUrl(url, { ___FROM___: start_date + '0000', ___TO___: end_date + '0000' });
            solrShieldNavigate(url);
        }
    };

    this.convertToDate = function(date) {
        return jQuery.datepicker.formatDate('yymmdd', date);
    };
}


jQuery(document).ready(function() {
    var dateRangeFacetController = new DateRangeFacetController();
    dateRangeFacetController.init();

    jQuery("body").on("tx_solr_updated", function() {
        dateRangeFacetController.init();
    });
});
