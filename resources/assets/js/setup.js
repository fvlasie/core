/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

document.addEventListener("DOMContentLoaded", () => {
    htmx.onLoad(function (content) {
        
        // Initialize all legacy Thickbox links as HTMX AJAX calls
        Array.from(document.getElementsByClassName('thickbox')).forEach((element) => {
            if (element.nodeName != 'A') return;
            
            element.setAttribute('hx-boost', 'true');
            element.setAttribute('hx-target', '#modalContent');
            element.setAttribute('hx-push-url', 'false');
            element.setAttribute('hx-swap', 'innerHTML show:no-scroll swap:0s');
            element.setAttribute('x-on:htmx:after-on-load', 'modalOpen = true');
            element.classList.remove('thickbox');

            element.setAttribute('x-on:click', element.getAttribute('href').includes('_delete') ? "modalType = 'delete'" : "modalType = 'view'");

            htmx.process(element);
        });

        // Convert all title attributes into x-tooltip attributes
        Array.from(document.querySelectorAll('[title]')).forEach((element) => {
            if (element.title != undefined && element.title != '') {
                element.setAttribute('x-tooltip', element.title.replaceAll('"', '\''));
                element.title = '';
            }
        });

        $(document).trigger('gibbon-setup');
    });

});

/**
 * Clear templated nodes from the document before saving it in history. This
 * prevents Alpine from creating duplicate objects when the history is loaded.
 */
document.addEventListener('htmx:beforeHistorySave', (event) => {
    document.querySelectorAll('[x-from-template]').forEach((e) => e.remove());
    document.querySelectorAll('.tox-tinymce').forEach((e) => e.remove());
    document.querySelectorAll('ul.token-input-list-facebook').forEach((e) => e.remove());
});


/**
 * TinyMCE
 *
 * Create pre-configured objects for different Editor modes, to be loaded 
 * as needed in the Editor.template.php file. Hook into settings using 
 * Gibbon.config.tinymce from index.php.
 */
const gibbonEditorUploadUrl = "./modules/User/form_editor_uploadAjaxProcess.php";

const gibbonIsTemporaryFileUrl = (value) => {
    return typeof value === "string" && (value.indexOf("blob:") === 0 || value.indexOf("data:") === 0);
};

const gibbonEscapeHtml = (value) => {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
};

const gibbonEditorHasTemporaryFileLink = (editor) => {
    const body = editor.getBody();
    return !!body && !!body.querySelector('a[href^="blob:"], a[href^="data:"]');
};

const gibbonWarnTemporaryFileLink = (editor) => {
    editor.notificationManager.open({
        text: Gibbon.config.tinymce.file_not_uploaded,
        type: "error",
    });
};

/**
 * Upload a file for the editor and resolve with the stored URL.
 * Temporary blob: and data: URLs are never returned.
 */
const gibbonUploadEditorFile = (file, filename, onProgress) => {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.withCredentials = true;
        xhr.open("POST", gibbonEditorUploadUrl);

        if (onProgress) {
            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    onProgress(event.loaded, event.total);
                }
            };
        }

        xhr.onload = () => {
            if (xhr.status < 200 || xhr.status >= 300) {
                reject(Gibbon.config.tinymce.error + " " + xhr.status + ": " + xhr.statusText);
                return;
            }

            let json;
            try {
                json = JSON.parse(xhr.responseText);
            } catch (e) {
                reject(Gibbon.config.tinymce.error + " " + xhr.responseText);
                return;
            }

            if (!json || typeof json.location != "string" || gibbonIsTemporaryFileUrl(json.location)) {
                reject(Gibbon.config.tinymce.error + " " + xhr.responseText);
                return;
            }

            resolve(json.location);
        };

        xhr.onerror = () => {
            reject(Gibbon.config.tinymce.error + " " + xhr.status + ": " + xhr.statusText);
        };

        const formData = new FormData();
        formData.append("file", file, filename);
        xhr.send(formData);
    });
};

const gibbonInsertUploadedFile = (editor, location, title) => {
    const safeLocation = gibbonEscapeHtml(location);
    const safeTitle = gibbonEscapeHtml(title || location);
    editor.execCommand("mceInsertContent", false, `<p><a href="${safeLocation}" data-fileupload="${safeTitle}">${safeTitle}</a></p>`);
};

const gibbonTinyMCEFileUpload = (editor) => ({
    title: Gibbon.config.tinymce.insert_file,
    body: {
        type: "panel",
        items: [
            {
                type: "urlinput",
                name: "file_upload",
                filetype: "file",
                label: Gibbon.config.tinymce.select_file_label,
                picker_text: Gibbon.config.tinymce.select_file,
            },
            {
                type: "htmlpanel",
                html: `<span style="font-size: 0.75rem; color: #888; display: inline-block; margin-top: 1rem;">${Gibbon.config.tinymce.file_types_label}: ${Gibbon.config.tinymce.file_types}</span>`,
            },
        ],
    },
    buttons: [
        {
            type: "cancel",
            name: "closeButton",
            text: Gibbon.config.tinymce.cancel,
        },
        {
            type: "submit",
            name: "submitButton",
            text: Gibbon.config.tinymce.save,
            buttonType: "primary",
        },
    ],
    onSubmit: (api) => {
        const data = api.getData();
        const value = data.file_upload ? data.file_upload.value : "";
        const meta = (data.file_upload && data.file_upload.meta) ? data.file_upload.meta : {};
        const title = meta.title || meta.text || "";

        const failure = (message) => {
            editor.setProgressState(false);
            editor.notificationManager.close();
            editor.notificationManager.open({ text: message, type: "error" });
            api.close();
        };

        const success = (location, linkTitle) => {
            if (gibbonIsTemporaryFileUrl(location)) {
                failure(Gibbon.config.tinymce.file_not_uploaded);
                return;
            }

            editor.setProgressState(false);
            editor.notificationManager.close();
            gibbonInsertUploadedFile(editor, location, linkTitle || title);
            api.close();
        };

        if (value == "") {
            editor.notificationManager.open({ text: Gibbon.config.tinymce.invalid_file, type: "warning" });
            api.close();
            return;
        }

        // A normal URL was pasted, or the file picker already uploaded it.
        if (!gibbonIsTemporaryFileUrl(value)) {
            success(value, title);
            return;
        }

        let blobInfo = null;
        try {
            const blobCache = editor.editorUpload.blobCache;
            blobInfo = (meta.id && blobCache.get(meta.id)) || blobCache.getByUri(value);
        } catch (e) {
            blobInfo = null;
        }

        if (!blobInfo) {
            failure(Gibbon.config.tinymce.file_not_uploaded);
            return;
        }

        const notification = editor.notificationManager.open({ text: Gibbon.config.tinymce.uploading, progressBar: true });
        editor.setProgressState(true);
        const filename = title || blobInfo.filename() || "file";

        gibbonUploadEditorFile(blobInfo.blob(), filename, (loaded, total) => {
            notification.progressBar.value((loaded / total) * 100);
        }).then((location) => {
            success(location, filename);
        }).catch((message) => {
            failure(message);
        });
    },
});

const gibbonTinyMCEDefaults = {
    language: Gibbon.config.tinymce.locale,
    directionality: Gibbon.config.tinymce.locale_rtl,
    license_key: 'gpl',
    width: '100%',
    resize: true,
    branding: false,
    onboarding: false,
    promotion: false,
    browser_spellcheck: true,
    convert_urls: false,
    relative_urls: false,
    
    valid_elements: Gibbon.config.tinymce.valid_elements,
    extended_valid_elements : Gibbon.config.tinymce.extended_valid_elements,
    invalid_elements: '',

    link_default_target: "_blank",
    link_context_toolbar: true,
    link_quicklink: true,

    image_advtab: true,
    images_upload_url: './modules/User/form_editor_uploadAjaxProcess.php',
    images_upload_credentials: true,
    // image_class_list: [
    //     { title: 'None', value: '' },
    //     { title: 'No border', value: 'img_no_border' },
    //     { title: 'Green border', value: 'img_green_border' },
    //     { title: 'Blue border', value: 'img_blue_border' },
    //     { title: 'Red border', value: 'img_red_border' }
    //   ],

    init_instance_callback: (editor) => {
        // Enable validation checking
        editor.on('blur', (e) => {
            tinymce.triggerSave();
            e.target.targetElm.dispatchEvent(new Event('blur'));
        });
    }
};

const gibbonTinyMCEMinimal = {
    menubar : false,
    toolbar: false,
    statusbar: false,
    contextmenu: 'cut copy paste pastetext | searchreplace | link | table styles fontfamily fontsize lineheight | forecolor backcolor | removeformat | code preview',

    plugins: 'autoresize table lists link image media quickbars code preview searchreplace',
    quickbars_selection_toolbar: 'bold italic underline | quicklink | h1 h2 h3 | alignleft aligncenter alignright | bullist numlist |  code',
    quickbars_insert_toolbar: 'quickimage media quicktable blockquote hr',
    quickbars_image_toolbar: 'alignleft aligncenter alignright',

    autoresize_bottom_margin: 0,
};

const gibbonTinyMCEInline = {
    inline: true,
    plugins: 'table lists link image media quickbars',
};

const gibbonTinyMCEFull = {
    statusbar: true,
    menubar : 'file edit view insert format table html',
    contextmenu: false,  //'cut copy paste pastetext | searchreplace | link | table | removeformat | code preview ',
    plugins: 'autosave table lists link image media quickbars wordcount charmap fullscreen code preview searchreplace',
    
    menu: {
        view: { title: Gibbon.config.tinymce.view, items: 'code wordcount | preview fullscreen' },
        html: { title: Gibbon.config.tinymce.html, items: 'code preview' },
    },

    toolbar_mode: 'floating',
    toolbar_groups: {
        formatting: {
          icon: 'typography',
          items: 'forecolor backcolor | h1 h2 h3 strikethrough blockquote | superscript subscript | removeformat'
        },
        styling: {
            icon: 'paragraph',
            items: 'blocks fontfamily fontsizeinput lineheight'
        },
        alignment: {
            icon: 'align-left',
            items: 'alignleft aligncenter alignright alignjustify | indent outdent'
        },
        upload: {
            icon: 'add-file',
            items: 'image media fileupload'
        },
    },
    toolbar: 'togglemenubar | bold italic underline formatting | styling link | alignment bullist numlist | upload table | charmap hr | code preview fullscreen',

    quickbars_selection_toolbar: false,
    quickbars_insert_toolbar: false,
    quickbars_image_toolbar: 'image | alignleft aligncenter alignright | imagedownload imagedelete',

    apply_source_formatting : true,
    autosave_restore_when_empty: true,

    file_picker_types: 'file image',

    /* and here's our custom image picker*/
    file_picker_callback: function (cb, value, meta) {
        const editor = this && this.editorUpload ? this : tinymce.activeEditor;
        const input = document.createElement('input');
        input.setAttribute('type', 'file');

        if (meta.filetype == 'image') {
            input.setAttribute('accept', 'image/*');
        }

        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) return;

            // Images stay on TinyMCE's upload pipeline, which replaces blob
            // sources when the editor content is saved.
            if (meta.filetype == 'image') {
                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    const id = 'blobid' + (new Date()).getTime();
                    const blobCache = editor.editorUpload.blobCache;
                    const base64 = reader.result.split(',')[1];
                    const blobInfo = blobCache.create(id, file, base64);
                    blobCache.add(blobInfo);

                    cb(blobInfo.blobUri(), { title: file.name, text: file.name, id: id });
                });
                reader.readAsDataURL(file);
                return;
            }

            // The link dialog writes this URL straight into href. Upload first
            // so a blob: address is never stored as the file link.
            const notification = editor.notificationManager.open({ text: Gibbon.config.tinymce.uploading, progressBar: true });
            gibbonUploadEditorFile(file, file.name).then((location) => {
                notification.close();
                cb(location, { text: file.name, title: file.name });
            }).catch((message) => {
                notification.close();
                editor.notificationManager.open({ text: message, type: 'error' });
            });
        });

        input.click();
    },
    
    color_map: [
        "#BFEDD2", "Light Green", 
        "#FBEEB8", "Light Yellow", 
        "#F8CAC6", "Light Red", 
        "#ECCAFA", "Light Purple", 
        "#C2E0F4", "Light Blue", 
        "#2DC26B", "Green", 
        "#F1C40F", "Yellow", 
        "#FF0000", "Red", 
        "#B96AD9", "Purple", 
        "#3598DB", "Blue", 
        "#169179", "Dark Turquoise", 
        "#E67E23", "Orange", 
        "#BA372A", "Dark Red", 
        "#843FA1", "Dark Purple", 
        "#236FA1", "Dark Blue", 
        "#ECF0F1", "Light Gray", 
        "#CED4D9", "Medium Gray", 
        "#95A5A6", "Gray", 
        "#7E8C8D", "Dark Gray", 
        "#34495E", "Navy Blue", 
        "#000000", "Black", 
        "#ffffff", "White", 
    ],

    setup: function (editor) {
        editor.ui.registry.addButton("togglemenubar", {
          tooltip: Gibbon.config.tinymce.advanced_options,
          icon: "settings",
          onAction: function () {
            const menubar = editor.getContainer().querySelector('.tox-menubar');
            if (menubar) {
                menubar.style.display = menubar.style.display == 'flex' ? 'none' : 'flex';
            }
          },
        });

        editor.ui.registry.addButton('fileupload', {
            icon: 'new-document',
            tooltip: Gibbon.config.tinymce.insert_file,
            onAction: () => editor.windowManager.open(gibbonTinyMCEFileUpload(editor))
        });

        editor.ui.registry.addButton('imagedownload', {
            icon: 'save',
            tooltip: Gibbon.config.tinymce.download,
            onAction: function() {
                const node = editor.selection.getNode();
                downloadLink = document.createElement('a');
                downloadLink.href = node.src;
                downloadLink.setAttribute('download', '');
                downloadLink.target = 'downloadIframe';
                downloadLink.click();
            }
        });

        editor.ui.registry.addButton('imagedelete', {
            icon: 'remove',
            tooltip: Gibbon.config.tinymce.delete,
            onAction: function() {
                const node = editor.selection.getNode();
                editor.dom.remove(node);
            }
        });

        const isFileLinkElement = (node) => {
            return node.nodeName.toLowerCase() === 'a' && node.href && node.dataset.fileupload;
          }
      
          const getFileLinkElement = () => {
            const node = editor.selection.getNode();
            return isFileLinkElement(node) ? node : null;
          };
      
          editor.ui.registry.addContextForm("fileedit", {
              launch: {
                  type: "contextformbutton",
                  icon: "new-document",
              },
              label: Gibbon.config.tinymce.file,
              predicate: isFileLinkElement,
              initValue: () => {
                  const elm = getFileLinkElement();
                  return !!elm ? elm.dataset.fileupload : "";
              },
              commands: [
                  {
                      type: "contextformbutton",
                      icon: "new-tab",
                      tooltip: Gibbon.config.tinymce.open,
                      onAction: (formApi) => {
                          const elm = getFileLinkElement();
                          window.open(elm.href, "_blank");
                          formApi.hide();
                      },
                  },
                  {
                      type: "contextformbutton",
                      icon: "save",
                      tooltip: Gibbon.config.tinymce.download,
                      onAction: (formApi) => {
                            const elm = getFileLinkElement();

                            downloadLink = document.createElement('a');
                            downloadLink.href = elm.href;
                            downloadLink.setAttribute('download', '');
                            downloadLink.target = 'downloadIframe';
                            downloadLink.click();

                            formApi.hide();
                      },
                  },
                  {
                      type: "contextformbutton",
                      icon: "remove",
                      tooltip: Gibbon.config.tinymce.delete,
                      onAction: (formApi) => {
                        editor.windowManager.confirm(Gibbon.config.tinymce.delete_confirm, (state) => {
                            if (!state) return;
                            const elm = getFileLinkElement();
                            editor.dom.remove(elm);
                          });
                          
                          formApi.hide();
                      },
                  },
              ],
          });
      },

    init_instance_callback: (editor) => {
        let lastTemporaryLinkWarning = 0;
        const blockTemporaryFileLink = (event) => {
            if (!gibbonEditorHasTemporaryFileLink(editor)) return false;

            if (event) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }

            const now = Date.now();
            if (now - lastTemporaryLinkWarning > 3000) {
                lastTemporaryLinkWarning = now;
                gibbonWarnTemporaryFileLink(editor);
            }
            return true;
        };

        if (editor.formElement) {
            editor.formElement.addEventListener("submit", blockTemporaryFileLink, true);
        }

        // Enable quick save from within tinymce
        editor.addShortcut("meta+s", "Custom Ctrl+S", function (e) {
            if (blockTemporaryFileLink(e)) return;
            tinymce.triggerSave();
            editor.formElement.dispatchEvent(new CustomEvent('quicksave'));
        });

        // Enable validation checking
        editor.on('blur', (e) => {
            tinymce.triggerSave();
            editor.targetElm.dispatchEvent(new Event('blur'));
        });

        // Enable input event checking
        editor.on('input', (e) => {
            editor.targetElm.dispatchEvent(new Event('input', { bubbles: true }));
        });

        // Autosave trigger
        if (editor.targetElm.hasAttribute('data-autosave')) {
            setTimeout(function () {
                editor.on('keydown', function () {
                    if (blockTemporaryFileLink()) return;
                    tinymce.triggerSave();
                    gibbonFormSubmitQuiet(document.getElementById(editor.formElement.id), editor.targetElm.getAttribute('data-autosave'))
                    editor.targetElm.dispatchEvent(new Event('input', { bubbles: true }));
                })
            }, 100);
        }
    }
};
