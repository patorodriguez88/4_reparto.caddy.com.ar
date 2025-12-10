module.exports = [

    {
        // Comman Vendors Js
        vendorJS: [
            "./node_modules/jquery/dist/jquery.min.js",
            "./node_modules/bootstrap/dist/js/bootstrap.bundle.js",
            "./node_modules/simplebar/dist/simplebar.min.js",
            "./node_modules/lucide/dist/umd/lucide.min.js",

            "./node_modules/select2/dist/js/select2.min.js",
            "./node_modules/daterangepicker/moment.min.js",
            "./node_modules/daterangepicker/daterangepicker.js",
            "./node_modules/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js",
            "./node_modules/bootstrap-timepicker/js/bootstrap-timepicker.min.js",
            "./node_modules/jquery-mask-plugin/dist/jquery.mask.min.js",
            "./node_modules/bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.js",
            "./node_modules/bootstrap-maxlength/dist/bootstrap-maxlength.min.js",
            "./node_modules/handlebars/dist/handlebars.min.js",
            "./node_modules/typeahead.js/dist/typeahead.bundle.min.js",
            "./node_modules/flatpickr/dist/flatpickr.min.js",
        ],

        vendorCSS: [
            "./node_modules/select2/dist/css/select2.min.css",
            "./node_modules/daterangepicker/daterangepicker.css",
            "./node_modules//bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.css",
            "./node_modules/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css",
            "./node_modules/bootstrap-timepicker/css/bootstrap-timepicker.min.css",
            "./node_modules/flatpickr/dist/flatpickr.min.css",
        ],

        // Icons
        iconLibs: [
            {
                name: "mdi",
                assets: [
                    "./node_modules/@mdi/font/css/materialdesignicons.min.css",
                    "./node_modules/@mdi/font/fonts/**"
                ]
            },
            {
                name: "unicons",
                assets: [
                    "./node_modules/@iconscout/unicons/css/unicons.css",
                    "./node_modules/@iconscout/unicons/fonts/**"
                ]
            },
            {
                name: "remixicon",
                assets: [
                    "./node_modules/remixicon/fonts/remixicon.css",
                    "./node_modules/remixicon/fonts/remixicon.eot",
                    "./node_modules/remixicon/fonts/remixicon.eot",
                    "./node_modules/remixicon/fonts/remixicon.woff2",
                    "./node_modules/remixicon/fonts/remixicon.woff",
                    "./node_modules/remixicon/fonts/remixicon.ttf",
                    "./node_modules/remixicon/fonts/remixicon.svg",
                ]
            }
        ]
    },

    {
        name: "apexcharts",
        assets: ["./node_modules/apexcharts/dist/apexcharts.min.js"]
    },

    {
        name: "bootstrap",
        assets: [
            "./node_modules/bootstrap/dist/js/bootstrap.bundle.min.js",
            "./node_modules/bootstrap/dist/css/bootstrap.min.css"
        ]
    },

    {
        name: "britecharts",
        assets: [
            "./node_modules/britecharts/dist/bundled/britecharts.min.js",
            "./node_modules/britecharts/dist/css/britecharts.min.css"
        ]
    },

    {
        name: "jquery",
        assets: ["./node_modules/jquery/dist/jquery.min.js"]
    },

    {
        name: "jquery",
        assets: ["./node_modules/jquery/dist/jquery.min.js"]
    },

    {
        name: "simplebar",
        assets: [
            "./node_modules/simplebar/dist/simplebar.min.js",
            "./node_modules/simplebar/dist/simplebar.min.css"
        ]
    },
    {
        name: "clipboard",
        assets: ["./node_modules/clipboard/dist/clipboard.min.js"]
    },

    {
        name: "bootstrap",
        assets: [
            "./node_modules/bootstrap/dist/js/bootstrap.bundle.min.js",
            "./node_modules/bootstrap/dist/css/bootstrap.min.css"
        ]
    },
    {
        name: "bootstrap-datepicker",
        assets: [
            "./node_modules/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js",
            "./node_modules/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css"
        ]
    },
    {
        name: "bootstrap-maxlength",
        assets: ["./node_modules/bootstrap-maxlength/dist/bootstrap-maxlength.min.js"]
    },
    {
        name: "bootstrap-timepicker",
        assets: [
            "./node_modules/bootstrap-timepicker/js/bootstrap-timepicker.min.js",
            "./node_modules/bootstrap-timepicker/css/bootstrap-timepicker.min.css"
        ]
    },
    {
        name: "bootstrap-touchspin",
        assets: [
            "./node_modules/bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.js",
            "./node_modules/bootstrap-touchspin/dist/jquery.bootstrap-touchspin.min.css"
        ]
    },
    {
        name: "chart.js",
        assets: ["./node_modules/chart.js/dist/chart.umd.js"]
    },
    {
        name: "jquery-sparkline",
        assets: ["./node_modules/jquery-sparkline/jquery.sparkline.min.js"]
    },
    {
        name: "frappe-gantt",
        assets: [
            "./node_modules/frappe-gantt/dist/frappe-gantt.min.js",
            "./node_modules/frappe-gantt/dist/frappe-gantt.min.css"
        ]
    },
    {
        name: "clipboard",
        assets: ["./node_modules/clipboard/dist/clipboard.min.js"]
    },
    {
        name: "fullcalendar",
        assets: ["./node_modules/fullcalendar/index.global.min.js"]
    },
    {
        name: "d3",
        assets: ["./node_modules/d3/dist/d3.min.js"]
    },
    {
        name: "daterangepicker",
        assets: [
            "./node_modules/daterangepicker/daterangepicker.js",
            "./node_modules/daterangepicker/daterangepicker.css"
        ]
    },
    {
        name: "dragula",
        assets: [
            "./node_modules/dragula/dist/dragula.min.js",
            "./node_modules/dragula/dist/dragula.min.css"
        ]
    },
    {
        name: "dropzone",
        assets: [
            "./node_modules/dropzone/dist/dropzone-min.js",
            "./node_modules/dropzone/dist/dropzone.css"
        ]
    },
    {
        name: "flatpickr",
        assets: [
            "./node_modules/flatpickr/dist/flatpickr.min.js",
            "./node_modules/flatpickr/dist/flatpickr.min.css"
        ]
    },
    {
        name: "glightbox",
        assets: [
            "./node_modules/glightbox/dist/js/glightbox.min.js",
            "./node_modules/glightbox/dist/css/glightbox.min.css"
        ]
    },
    {
        name: "ion-rangeslider",
        assets: [
            "./node_modules/ion-rangeslider/js/ion.rangeSlider.min.js",
            "./node_modules/ion-rangeslider/css/ion.rangeSlider.min.css"
        ]
    },
    {
        name: "jquery",
        assets: ["./node_modules/jquery/dist/jquery.min.js"]
    },
    {
        name: "jquery-ui",
        assets: [
            "./node_modules/jquery-ui/dist/jquery-ui.min.js",
            "./node_modules/jquery-ui/themes/base/all.css"
        ]
    },
    {
        name: "jsvectormap",
        assets: [
            "./node_modules/jsvectormap/dist/jsvectormap.min.js",
            "./node_modules/jsvectormap/dist/maps/world.js",
            "./node_modules/jsvectormap/dist/maps/world-merc.js",
            "./node_modules/jsvectormap/dist/jsvectormap.min.css"
        ]
    },

    {
        name: "jquery-toast-plugin",
        assets: [
            "./node_modules/jquery-toast-plugin/dist/jquery.toast.min.js",
            "./node_modules/jquery-toast-plugin/dist/jquery.toast.min.css",
        ]
    },

    {
        name: "twitter-bootstrap-wizard",
        assets: [
            "./node_modules/twitter-bootstrap-wizard/jquery.bootstrap.wizard.min.js",
        ]
    },
    {
        name: "prismjs",
        assets: [
            "./node_modules/prismjs/prism.js",
            "./node_modules/prismjs/plugins/normalize-whitespace/prism-normalize-whitespace.min.js",
        ],
    },

    {
        name: "clipboard",
        assets: [
            "./node_modules/clipboard/dist/clipboard.min.js",
        ],
    },


    {
        name: "jstree",
        assets: [
            "./node_modules/jstree/dist/jstree.min.js",
            "./node_modules/jstree/dist/themes/default/style.min.css",
            "./node_modules/jstree/dist/themes/default/throbber.gif"
        ]
    },
    {
        name: "rateit",
        assets: [
            "./node_modules/jquery.rateit/scripts/jquery.rateit.min.js",
            "./node_modules/jquery.rateit/scripts/rateit.css",
            "./node_modules/jquery.rateit/scripts/star.gif",
            "./node_modules/jquery.rateit/scripts/delete.gif",
        ]
    },
    {
        name: "moment",
        assets: ["./node_modules/moment/min/moment.min.js"]
    },
    {
        name: "quill",
        assets: [
            "./node_modules/quill/dist/quill.js",
            "./node_modules/quill/dist/quill.core.css",
            "./node_modules/quill/dist/quill.snow.css",
            "./node_modules/quill/dist/quill.bubble.css"
        ]
    },
    {
        name: "select2",
        assets: [
            "./node_modules/select2/dist/js/select2.min.js",
            "./node_modules/select2/dist/css/select2.min.css"
        ]
    },
    {
        name: "simplebar",
        assets: [
            "./node_modules/simplebar/dist/simplebar.min.js",
            "./node_modules/simplebar/dist/simplebar.min.css"
        ]
    },
    {
        name: "simplemde",
        assets: [
            "./node_modules/simplemde/dist/simplemde.min.js",
            "./node_modules/simplemde/dist/simplemde.min.css"
        ]
    },
    {
        name: "typeahead.js",
        assets: ["./node_modules/typeahead.js/dist/typeahead.bundle.min.js"]
    },
    {
        name: "datatables",
        assets: [
            "./node_modules/datatables.net/js/dataTables.min.js",
            "./node_modules/datatables.net-bs5/js/dataTables.bootstrap5.min.js",
            "./node_modules/datatables.net-bs5/css/dataTables.bootstrap5.min.css",
            "./node_modules/datatables.net-responsive/js/dataTables.responsive.min.js",
            "./node_modules/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js",
            "./node_modules/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css",
            "./node_modules/datatables.net-buttons/js/dataTables.buttons.min.js",
            "./node_modules/datatables.net-buttons-bs5/js/buttons.bootstrap5.min.js",
            "./node_modules/jszip/dist/jszip.min.js",
            "./node_modules/pdfmake/build/pdfmake.min.js",
            "./node_modules/pdfmake/build/vfs_fonts.js",
            "./node_modules/datatables.net-buttons/js/buttons.html5.min.js",
            "./node_modules/datatables.net-buttons/js/buttons.print.min.js",

            "./node_modules/datatables.net-keytable/js/dataTables.keyTable.min.js",

            "./node_modules/datatables.net-select/js/dataTables.select.min.js",
            "./node_modules/datatables.net-select-bs5/js/select.bootstrap5.min.js",

            "./node_modules/datatables.net-fixedheader/js/dataTables.fixedHeader.min.js",
            "./node_modules/datatables.net-fixedheader-bs5/js/fixedHeader.bootstrap5.min.js",

            "./node_modules/datatables.net-fixedcolumns-bs5/css/fixedColumns.bootstrap5.min.css",
            "./node_modules/datatables.net-fixedcolumns-bs5/js/fixedColumns.bootstrap5.min.js",

            "./node_modules/datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css",
            "./node_modules/datatables.net-buttons-bs5/css/buttons.bootstrap5.min.css",
            "./node_modules/datatables.net-select-bs5/css/select.bootstrap5.min.css",
            "./node_modules/datatables.net-fixedheader-bs5/css/fixedHeader.bootstrap5.min.css"
        ]
    }

];