/*
 * This file is part of Jarves.
 *
 * (c) Marc J. Schmidt <marc@marcjschmidt.de>
 *
 *     J.A.R.V.E.S - Just A Rather Very Easy [content management] System.
 *
 *     http://jarves.io
 *
 * To get the full copyright and license information, please view the
 * LICENSE file, that was distributed with this source code.
 */

jarves.FieldTypes.PageContents = new Class({
    Extends: jarves.FieldAbstract,

    options: {
        /**
         * If we display the save buttons etc.
         */
        standalone: false
    },

    preview: 0,
    currentNode: null,
    currentDomain: null,
    currentLayout: null,
    backupedPageLayout: null,
    lastKnownPageType: null,

    currentContents: null,

    lastLoadedTreeForDomainId: null,

    createLayout: function() {

        if (this.options.standalone) {
            this.titleContainer = this.getWin().getTitleGroupContainer();
            this.container = this.getWin().getContentContainer();
        } else {
            this.mainLayout = new jarves.Layout(this.getContainer(), {
                layout: [
                    {columns: [null], height: 50},
                    {columns: [null]}
                ]
            });
            this.titleContainer = this.mainLayout.getCell(1, 1);
            this.container = this.mainLayout.getCell(2, 1);
            this.container.addClass('jarves-ActionBar jarves-Field-Content-ActionBar');
            this.mainLayout.getTd(1, 1).set('colspan', 2);

            if (this.getForm().getField('type')) {
                this.getForm().getField('type').addEvent('change', function (typeValue) {

                    if (!this.layoutSelection) {
                        //editor not yet completely loaded through setValue() call, so ignore
                        return;
                    }

                    this.updateLayout(typeValue);
                    // console.log('change type', typeValue, this.lastKnownPageType);

                    if (!this.lastKnownPageType || typeValue !== this.lastKnownPageType) {
                        if (0 === typeValue) {
                            //switched from tray to page, so set as layout not `_tray`.
                            this.loadEditor(this.currentDomain, this.currentNode, this.currentContents, this.backupedPageLayout);
                        } else {
                            this.backupedPageLayout = this.currentLayout;
                            this.loadEditor(this.currentDomain, this.currentNode, this.currentContents, '_tray');
                        }
                    }
                    this.lastKnownPageType = typeValue;
                }.bind(this))
            }
        }

        this.headerLayout = new jarves.Layout(this.titleContainer, {
            fixed: false,
            layout: [
                {columns: [null, 100]}
            ]
        });
        this.headerLayout.getCell(1, 1).addClass('jarves-ActionBar-left');

        if (this.options.standalone) {
            this.treeButtonGroup = new jarves.ButtonGroup(this.headerLayout.getCell(1, 1));
            this.treeBtn = this.treeButtonGroup.addButton(t('Tree'), '#icon-tree', this.toggleTree.bind(this));
        }

        this.buttonGroup = new jarves.ButtonGroup(this.headerLayout.getCell(1, 1));
        this.layoutBtn = this.buttonGroup.addButton(t(''), '#icon-layout');
        this.listBtn = this.buttonGroup.addButton(t(''), '#icon-list-4');

        this.layoutBtn.setPressed(true);

        this.headerLayout.getCell(1, 2).setStyle('text-align', 'right');
        this.headerLayout.getCell(1, 2).setStyle('white-space', 'nowrap');

        this.layoutSelectionLabel = new Element('span', {
            text: t('Layout:'),
            style: 'line-height: 30px; display: inline-block; padding-right: 5px;'
        }).inject(this.headerLayout.getCell(1, 2));

        this.layoutSelectionContainer = new Element('span').inject(this.headerLayout.getCell(1, 2));

        if (this.options.standalone) {
            this.actionGroup = new jarves.ButtonGroup(this.headerLayout.getCell(1, 2));

            this.actionGroup.addButton(t('Reset'), '#icon-escape');
            this.actionGroup.addButton(t('Versions'), '#icon-history');

            this.saveBtn = new jarves.Button(t('Save'))
                .setButtonStyle('blue')
                .addEvent('click', this.saveStandalone.bind(this))
                .inject(this.headerLayout.getCell(1, 2));
        } else {
            this.titleContainer.addClass('jarves-Field-content-actionBar');
        }

        this.getWin().setTitle(t('Home'));

        this.editableAreaContainer = new Element('div', {
            style: 'position: absolute; left: 0px; right: 0px; top: 0px; bottom: 0px;'
        }).inject(this.container);

        this.editableAreaLayout = new jarves.Layout(this.editableAreaContainer, {
            layout: [
                {columns: [null], height: 30},
                {columns: [null]}
            ]
        });

        this.optionsContainer = new Element('div').inject(this.editableAreaLayout.getCell(1, 1));

        this.zoomContainer = new Element('div', {
            style: 'float: right;'
        }).inject(this.optionsContainer);

        new Element('span', {
            text: t('Zoom:'),
            style: 'padding-right: 5px; line-height: 30px;'
        }).inject(this.zoomContainer);

        this.slider = new jarves.Slider(this.zoomContainer, {
            steps: 100
        });

        this.zoomValue = new Element('span', {
            text: '100%',
            style: 'padding-left: 5px; line-height: 30px;'
        }).inject(this.zoomContainer);

        this.slider.setValue(100);

//        this.toggleFullscreen = new Element('a', {
//            'class': 'jarves-button-icon icon-expand-5',
//            style: 'padding: 0 15px; margin-left: 15px; margin-right: 0px; border-left: 1px solid #ddd; border-right: 1px solid #ddd;',
//            title: t('Toogle Fullscreen')
//        }).inject(this.zoomContainer);

        var iframeContainer = this.editableAreaLayout.getCell(2, 1);

        this.iframe = new IFrame({
            frameborder: 0,
            style: 'display: block; border: 0; height: 100%; width: 100%;'
        }).inject(iframeContainer);

        iframeContainer.addClass('jarves-PageContents-iframe');

        if (this.options.standalone) {
            this.domainSelection = new jarves.Select(this.headerLayout.getCell(1, 1), {
                object: 'jarves/domain',
                onChange: function(item) {
                    this.currentNode = null;
                    this.loadEditor(this.domainSelection.getValue());
                    this.loadTreeForDomain(this.domainSelection.getValue());
                }.bind(this)
            });
        }

        this.iframeOverlay = new Element('div', {
            'class': 'jarves-Full',
            styles: {
                opacity: 0.01,
                backgroundColor: '#fff'
            }
        });

//        var splitter = this.mainLayout.getSplitter(2, 2, 'left');
//        splitter.addEvent('start', function() {
//            this.iframeOverlay.inject(iframeContainer);
//        }.bind(this));
//
//        splitter.addEvent('end', function() {
//            this.iframeOverlay.dispose()
//        }.bind(this));

        this.slider.addEvent('change', function(step) {
            if (0 == step) step = 1;
            this.zoomValue.set('text', step + '%');
            var val = step / 100;
            document.id(this.iframe.contentWindow.document.body).setStyle('zoom', step + '%');
        }.bind(this));

        this.setupEvents();
    },

    getFrame: function() {
        return this.iframe;
    },

    toggleTree: function() {
        if (this.treeBtn.isPressed()) {
            this.treeBtn.setPressed(false);
            this.hideTree();
        } else {
            this.treeBtn.setPressed(true);
            this.showTree();
        }
    },

    hideTree: function() {
        this.editableAreaContainer.setStyle('left', 0);

        if (this.treeContainer) {
            this.treeContainer.setStyle('display', 'none');
        }
    },

    showTree: function() {
        if (!this.treeContainer) {
            this.treeContainer = new Element('div', {
                'class': 'jarves-scrolling jarves-Field-content-treeContainer'
            }).inject(this.container);
        }

        this.editableAreaContainer.setStyle('left', 300);
        this.treeContainer.setStyle('display');

        var domainId = this.domainSelection.getValue();
        if (domainId && this.lastLoadedTreeForDomainId !== domainId) {
            this.loadTreeForDomain(domainId);
        }
    },

    adjustAnchors: function() {

        var params = {
            '_jarves_editor': 1,
            '_jarves_editor_id': this.getEditor().getId(),
            '_jarves_editor_domain': this.getDomainId(),
            '_jarves_editor_options': {
                standalone: this.options.standalone
            }
        };
        params = Object.toQueryString(params);

        this.getEditor().getContainer().getElements('a').each(function(a) {
            if (a.href) {
                a.href = a.href + ((a.href.indexOf('?') > 0) ? '&' : '?') + params
            }
        }.bind(this));
    },

    /**
     * @param {Number} domainId
     */
    loadTreeForDomain: function(domainId) {
        if (!this.treeContainer) {
            return;
        }

        this.treeContainer.empty();

        this.treeField = new jarves.Field({
            type: 'tree',
            object: 'jarves/node',
            width: 'auto',
            options: {
                scope: domainId
            }
        }, this.treeContainer);

        this.treeField.addEvent('change', function(value){

            /** @var {jarves.FieldTypes.Tree} treeField */
            var treeField = this.treeField.getFieldObject();

            /** @var {jarves.ObjectTree} objectTree */
            var objectTree = treeField.getSelectedTree();

            var objectUrl = objectTree.getSelectedUrl();

            var objectKey = jarves.getCroppedObjectKey(objectUrl);
            var pk = jarves.getObjectPkFromUrlId(objectKey, objectUrl);

            if ('jarves/node' === objectKey && this.currentNode && this.currentNode === pk.id) {
                //node already selected
                return;
            }

            if ('jarves/domain' === objectKey) {
                //clicked on the domain, we reload complete editor
                this.currentNode = null;
                this.loadStandaloneEditor(domainId);
            } else {
                //click on the node, so pk.id == node.id
                this.loadStandaloneEditor(domainId, pk.id);
            }

        }.bind(this));

        if (this.getEditor()) {
            this.treeField.setValue('jarves/node/' + this.getNodeId());
        }
        this.lastLoadedTreeForDomainId = domainId;
    },

    /**
     *
     * @returns {Element}
     */
    getOptionsContainer: function() {
        return this.optionsContainer;
    },

    setupEvents: function() {
        var typeField;
        if (this.getField().getForm() && (typeField = this.getField().getForm().getField('type'))) {
//            typeField.addEvent('change', function() {
//                this.setValue();
//            }.bind(this));
        }
    },

    getValue: function() {
        var content = this.editor ? this.editor.getValue() : this.currentContents;

        return new jarves.MultiValue(content, {
            layout: this.currentLayout || this.firstSelectedLayout
        });
    },

    setValue: function(value, internal) {
        if (this.getField().getForm()) {
            this.setValueFromForm(value, internal);
        } else {
            this.setValueDefault(value, internal);
        }
    },

    setValueDefault: function(contents, internal) {
        // if (!contents) {
        //     contents = this.lastContents;
        // } else {
        //     this.lastContents = contents;
        // }

        this.currentContents = contents;
        this.loadEditor(this.domainSelection.getValue(), this.currentNode, contents);
    },

    setValueFromForm: function(value, internal) {
        this.currentContents = value ? value.content : null;
        var originValue = this.getField().getForm().getOriginValue();

        var typeValue = this.getField().getForm().getValue('type');

        if (0 != typeValue && 3 != typeValue) {
            return;
        }

        this.lastKnownPageType = typeValue;
        this.updateLayout(typeValue);

        if (3 === typeValue){
            //tray
            this.currentLayout = '_tray';
        } else {
            this.backupedPageLayout = value.layout;
            this.currentLayout = value.layout;
        }

        this.reloadLayoutSelection(value.theme || originValue.domain.theme, this.currentLayout);

        // this.currentNode = originValue.id;
        // this.currentDomain = originValue.domainId;
        this.loadEditor(originValue.domainId, originValue.id, this.currentContents);
    },

    updateLayout: function(nodeType) {
        if (3 === nodeType){
            //tray
            this.mainLayout.hideRow(1);
        } else {
            this.mainLayout.showRow(1);
        }
    },

    onLayoutSelectFirst: function(layout) {
        this.firstSelectedLayout = layout;
        this.backupedPageLayout = layout;
    },

    onLayoutChange: function(layout) {
        // console.error('onLayoutChange', layout);
        // this.currentContents = this.editor ? this.editor.getValue() : this.currentContents;
        this.loadEditor(this.currentDomain, this.currentNode, this.currentContents, layout);
    },

    reloadLayoutSelection: function(themeId, layoutId) {
        this.layoutSelectionContainer.empty();

        this.layoutSelection = new jarves.Field({
            noWrapper: true,
            type: 'layout',
            value: layoutId,
            options: {
                onChange: this.onLayoutChange.bind(this),
                onSelectFirst: this.onLayoutSelectFirst.bind(this),
                theme: themeId
            }
        }, this.layoutSelectionContainer);
    },

    loadStandaloneEditor: function(domainId, nodeId) {
        this.currentLayout = null;
        this.firstSelectedLayout = null;
        this.loadEditor(domainId, nodeId, null, null);
    },

    loadEditor: function(domainId, nodeId, contents, targetLayout) {
        var options = {
            standalone: this.options.standalone
        };

        // console.log('loadEditor', targetLayout, this.currentLayout || this.firstSelectedLayout);

        if (!targetLayout) {
            targetLayout = this.currentLayout || this.firstSelectedLayout;
        }

        if (!this.options.standalone && this.currentNode && this.currentNode == nodeId && this.currentLayout == targetLayout && this.getEditor()) {
            this.getEditor().setValue(contents);
            return;
        }

        this.currentLayout = targetLayout;
        if (this.layoutSelection) {
            this.layoutSelection.setValue(targetLayout);
        }

        // this.layoutSelection.setValue(targetLayout);

        this.editor = null;

        var id = (Math.random() * 10 * (Math.random() * 10)).toString(36).slice(3);

        if (this.lastJarvesEditorLoader) {
            window.removeEvent('jarvesEditorLoaded', this.lastJarvesEditorLoader);
        }

        this.lastJarvesEditorLoader = function(editor) {
            if (editor && editor.getId() == id) {
                this.setEditor(editor);
                editor.setContentField(this);

                this.currentNode = this.getNodeId();
                this.currentLayout = this.getLayout();

                if (!this.options.standalone) {
                    editor.deactivateLinks();
                    editor.setValue(contents);
                } else {
                    this.reloadLayoutSelection(this.getTheme(), this.getLayout());
                }
                if (this.treeField) {
                    this.treeField.setValue('jarves/node/' + this.getNodeId());
                }
                this.adjustAnchors();

                this.getEditor().getContainer().addClass(Modernizr.touch ? 'jarves-touch' : 'jarves-no-touch');
            }
        }.bind(this);
        window.addEvent('jarvesEditorLoaded', this.lastJarvesEditorLoader);

        if (!nodeId && this.currentNode) {
            nodeId = this.currentNode;
        }

        if (!domainId && this.currentDomain) {
            domainId = this.currentDomain;
        }

        var path = _pathAdmin;
        if ('/' === path.substr(-1)) {
            path = path.substr(0, path.length - 1);
        }

        path = path.dirname();

        var params = {
            '_jarves_editor': 1,
            '_jarves_editor_id': id,
            '_jarves_editor_node': nodeId,
            '_jarves_editor_domain': domainId,
            '_jarves_editor_layout': targetLayout,
            '_jarves_editor_options': options
        };

        this.iframe.set('src', path + '?' + Object.toQueryString(params));
    },

    getContainerOffsetY: function() {
        var y = this.getFrame().getPosition(this.getWin()).y; //relative to current window
        if ('iframe' === this.getFrame().get('tag')) {
            y -= this.getFrame().contentWindow.document.body.getScroll().y;
        }

        return y;
    },

    getNode: function() {
        return this.getEditor().options.node;
    },

    getNodeId: function() {
        return this.getEditor() ? this.getEditor().options.node.id : null;
    },

    getDomainId: function() {
        return this.getEditor().options.domain.id;
    },

    getTheme: function() {
        return this.getEditor().options.node.theme || this.getEditor().options.domain.theme;
    },

    getLayout: function() {
        return this.getEditor().options.node.layout;
    },

    /**
     *
     * @param {jarves.ProgressWatch} progressWatch
     */
    save: function(progressWatch) {
        if (!this.editor) {
            progressWatch.done();
            return;
        }

        var progressWatchManager = new jarves.ProgressWatchManager({
            onAllSuccess: function() {
                progressWatch.done();
            }.bind(this),
            onError: function(progressWatch) {
                progressWatch.error();
            },
            onAllProgress: function(progress) {
                progressWatch.setProgress(progress);
            }.bind(this)
        });

        this.editor.save(progressWatchManager);
    },

    saveStandalone: function() {
        if (this.editor) {

            this.saveBtn.startLoading(t('Saving ...'));

            if (this.lastSaveRq) {
                this.lastSaveRq.cancel();
            }

            var progressWatchManager = new jarves.ProgressWatchManager({
                onAllSuccess: function() {
                    var value = this.editor.getValue(); // all saved, we can now safely retrieve the value
                    this.saveBtn.setProgress(100);
                    this.lastSaveRq = new Request.JSON({url: this.getUrl(),
                        onFailure: function(response) {
                            this.saveBtn.failedLoading(t('Failed!'));
                            this.saveBtn.setProgress(false);
                        }.bind(this),
                        onComplete: function(response) {
                            this.saveBtn.doneLoading(t('Saved!'));
                            this.saveBtn.setProgress(false);
                        }.bind(this)
                    }).patch({content: value, layout: this.currentLayout || this.firstSelectedLayout});
                }.bind(this),
                onError: function(progressWatch) {
                    this.saveBtn.startLoading(t('Failed'));
                },
                onAllProgress: function(progress) {
                    this.saveBtn.setProgress(progress);
                }.bind(this)
            });

            this.saveBtn.startLoading(t('Saving ...'));
            this.editor.save(progressWatchManager);
        }
    },

    //    getValue: function(progressWatch) {
    //        if (this.editor) {
    //            var value = [];
    //            var progressWatchManager = new jarves.ProgressWatchManager({
    //                onDone: function(progressWatch) {
    //                    value.push(progressWatch.getValue());
    //                },
    //                onAllDone: function() {
    //                    progressWatch.done(value);
    //                }.bind(this),
    //
    //                onAllProgress: function(progress) {
    //                    progressWatch.progress(progress);
    //                }.bind(this)
    //            });
    //
    //            return this.editor.getValue(progressWatchManager);
    //        } else {
    //            return progressWatch.done(this.lastContents);
    //        }
    //    },

    getUrl: function() {
        return _pathAdmin + 'object/jarves/node/' + this.editor.options.node.id;
    },

    /**
     *
     * @param {jarves.Editor} editor
     */
    setEditor: function(editor) {
        this.editor = editor;
    },

    /**
     *
     * @returns {jarves.Editor}
     */
    getEditor: function() {
        return this.editor;
    },

    dragStart: function(item, e) {
        var data = {};
        data.type = item.kaContentType;

        if (item.kaContentValue) {
            data.content = item.kaContentValue;
        }

        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('application/json', JSON.encode(data));

        if (this.editor) {
            this.editor.highlightSlotsBubbles(true);
        }
    },

    dragEnd: function(item, e) {
        if (this.editor) {
            this.editor.highlightSlotsBubbles(false);
        }
    }

});