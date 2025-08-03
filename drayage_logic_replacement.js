// CORRECTED DRAYAGE LOGIC 
// Replace everything from line 2412 to line 2783 in manage_warehouse_inventory.php with this:

                // Create form data for shipment using create_shipment.php format
                const formData = new FormData();
                
                // Add pallet IDs in the format expected by create_shipment.php
                palletIds.forEach((palletId, index) => {
                    formData.append(`selected_pallets[${index}]`, palletId);
                });
                
                // Basic shipment info
                formData.append('departure_date', document.getElementById('move_departure_date').value);
                formData.append('est_arrival_date', document.getElementById('move_est_arrival_date').value);
                formData.append('freight_cost', document.getElementById('move_freight_cost').value || '0');
                formData.append('customer_cost', document.getElementById('move_customer_cost').value || '0');
                formData.append('miles', document.getElementById('move_miles').value || '0');
                
                // Origin info (current warehouse/port)
                formData.append('origin_type', 'warehouse');
                formData.append('origin_id', '<?php echo $warehouse_id; ?>');
                
                // Destination info
                const destinationType = document.querySelector('input[name="destination_type"]:checked').value;
                const destinationId = document.getElementById('move_destination_id').value;
                formData.append('destination_type', destinationType);
                formData.append('destination_id', destinationId);
                
                // BOL number and container number
                formData.append('bol_number', drayageBolNumber);
                formData.append('container_number', containerNumber);
                formData.append('action', 'ship_pallets');
                
                // Add Generate BOL flag if needed
                if (shouldGenerateBol) {
                    formData.append('generate_bol', '1');
                }
                
                console.log('Creating drayage shipment with data:', Object.fromEntries(formData));
                
                // First, create the drayage shipment
                fetch('create_shipment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Drayage shipment response status:', response.status);
                    
                    if (!response.ok) {
                        throw new Error(`Failed to create drayage shipment: ${response.status}`);
                    }
                    
                    // Shipment created successfully, now update original container deliveries
                    console.log('Drayage shipment created successfully, updating original container deliveries...');
                    
                    return fetch('update_container_deliveries.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            delivery_ids: containerIds,
                            container_number: containerNumber
                        })
                    });
                })
                .then(updateResponse => {
                    if (!updateResponse.ok) {
                        throw new Error(`Failed to update container deliveries: ${updateResponse.status}`);
                    }
                    return updateResponse.json();
                })
                .then(updateResult => {
                    console.log('Container update result:', updateResult);
                    
                    if (!updateResult.success) {
                        console.warn('Warning: Failed to update container deliveries:', updateResult.message);
                    }
                    
                    // Handle BOL generation or success message
                    if (shouldGenerateBol) {
                        console.log('Redirecting to BOL generation...');
                        
                        // Create form and submit for BOL generation
                        const bolForm = document.createElement('form');
                        bolForm.method = 'POST';
                        bolForm.action = 'create_shipment.php';
                        bolForm.style.display = 'none';
                        
                        // Add all form data as hidden inputs
                        for (let [key, value] of formData.entries()) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = value;
                            bolForm.appendChild(input);
                        }
                        
                        document.body.appendChild(bolForm);
                        bolForm.submit();
                        return;
                    } else {
                        // Show success message
                        closeMoveContainerModal();
                        
                        const deliveryCount = containerIds.length;
                        const deliveryWord = deliveryCount === 1 ? 'delivery' : 'deliveries';
                        let successMsg = `${deliveryCount} drayage ${deliveryWord} successfully created.`;
                        
                        if (destinationType === 'warehouse') {
                            successMsg += ` Container(s) are now in transit to the selected warehouse.`;
                        } else {
                            successMsg += ` Container(s) are now in transit to the selected project.`;
                        }
                        
                        sessionStorage.setItem('move_container_success', successMsg);
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error in drayage shipment process:', error);
                    alert('Error creating drayage shipment: ' + error.message);
                    
                    // Re-enable the submit button
                    const submitButton = document.querySelector('#moveContainerModal .action-button');
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.textContent = 'Create Drayage Shipment';
                    }
                });